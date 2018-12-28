<?php
/**
 * Copyright 2015 SIGNIFYD Inc. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Signifyd\Connect\Model;

use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;

/**
 * ORM model declaration for case data
 */
class Casedata extends AbstractModel
{
    /**
     * @var \Signifyd\Connect\Helper\ConfigHelper
     */
    protected $configHelper;

    /**
     * @var InvoiceService
     */
    private $invoiceService;

    /**
     * @var InvoiceSender
     */
    protected $invoiceSender;

    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @var \Magento\Sales\Model\OrderFactory
     */
    protected $orderFactory;

    /**
     * @var \Magento\Sales\Model\Order
     */
    protected $order;

    /**
     * Casedata constructor.
     * @param Context $context
     * @param Registry $registry
     * @param \Signifyd\Connect\Helper\ConfigHelper $configHelper
     * @param InvoiceService $invoiceService
     */
    public function __construct(
        Context $context,
        Registry $registry,
        \Signifyd\Connect\Helper\ConfigHelper $configHelper,
        InvoiceService $invoiceService,
        InvoiceSender $invoiceSender,
        ObjectManagerInterface $objectManager,
        \Magento\Sales\Model\OrderFactory $orderFactory
    )
    {
        $this->configHelper = $configHelper;
        $this->invoiceService = $invoiceService;
        $this->invoiceSender = $invoiceSender;
        $this->objectManager = $objectManager;
        $this->orderFactory = $orderFactory;

        parent::__construct($context, $registry);
    }

    /**
     * Constructor
     *
     * @return void
     */
    protected function _construct()
    {
        parent::_construct();
        $this->_init('Signifyd\Connect\Model\ResourceModel\Casedata');
    }

    public function getOrder()
    {
        if (isset($this->order) == false) {
            $incrementId = $this->getOrderIncrement();

            if (empty($incrementId) == false) {
                $this->order = $this->orderFactory->create()->loadByIncrementId($incrementId);
            }
        }

        return $this->order;
    }

    /**
     * @param $caseData
     * @return bool
     */
    public function updateCase($caseData)
    {
        /** @var $case \Signifyd\Connect\Model\Casedata */
        $case = $caseData['case'];
        $request = $caseData['request'];
        $order = $caseData['order'];

        $orderAction = array("action" => null, "reason" => '');
        if (isset($request->score) && $case->getScore() != $request->score) {
            $case->setScore($request->score);
            $order->setSignifydScore($request->score);
            $orderAction = $this->handleScoreChange($caseData) ?: $orderAction;
        }

        if (isset($request->status) && $case->getSignifydStatus() != $request->status) {
            $case->setSignifydStatus($request->status);
            $orderAction = $this->handleStatusChange($caseData) ?: $orderAction;
        }

        if (isset($request->guaranteeDisposition) && $case->getGuarantee() != $request->guaranteeDisposition) {
            $case->setGuarantee($request->guaranteeDisposition);
            $order->setSignifydGuarantee($request->guaranteeDisposition);
            $orderAction = $this->handleGuaranteeChange($caseData) ?: $orderAction;
        }

        $case->setCode($request->caseId);
        $order->setSignifydCode($request->caseId);

        $guarantee = $case->getGuarantee();
        $score = $case->getScore();
        if (empty($guarantee) == false && $guarantee != 'N/A' && empty($score) == false) {
            $case->setMagentoStatus(CaseRetry::PROCESSING_RESPONSE_STATUS);
            $case->setUpdated(strftime('%Y-%m-%d %H:%M:%S', time()));
        }

        if (isset($request->testInvestigation)) {
            $case->setEntries('testInvestigation', $request->testInvestigation);
        }

        try{
            $order->getResource()->save($order);
            $this->getResource()->save($case);
            $this->updateOrder($caseData, $orderAction, $case);
            $this->_logger->info('Case was saved, id:' . $case->getIncrementId());
        } catch (\Exception $e){
            $this->_logger->critical($e->__toString());
            return false;
        }


        return true;
    }

    /**
     * @param array $caseData
     * @param array $orderAction
     * @param \Signifyd\Connect\Model\Casedata $case
     * @return bool
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function updateOrder($caseData, $orderAction, $case)
    {
        $this->_logger->debug("Update order with action: " . print_r($orderAction, true));

        /** @var $order \Magento\Sales\Model\Order */
        $order = $caseData['order'];
        $completeCase = false;

        $completeOrderStates = array(Order::STATE_CANCELED, Order::STATE_COMPLETE, Order::STATE_CLOSED);

        if (in_array($order->getState(), $completeOrderStates)) {
            $completeCase = true;
        }

        switch ($orderAction["action"]) {
            case "hold":
                if ($order->canHold()) {
                    try {
                        $order->hold();
                        $completeCase = true;
                    } catch (\Exception $e){
                        $this->_logger->debug($e->__toString());
                        return false;
                    }
                } else {
                    $notHoldableStates = [
                        Order::STATE_CANCELED,
                        Order::STATE_PAYMENT_REVIEW,
                        Order::STATE_COMPLETE,
                        Order::STATE_CLOSED,
                        Order::STATE_HOLDED
                    ];

                    if ($order->getState() == Order::STATE_HOLDED) {
                        $completeCase = true;
                    }

                    if (in_array($order->getState(), $notHoldableStates)) {
                        $reason = "order is on {$order->getState()} state";
                    } elseif ($order->getActionFlag(Order::ACTION_FLAG_HOLD) === false) {
                        $reason = "order action flag is set to do not hold";
                    } else {
                        $reason = "unknown reason";
                    }

                    $this->_logger->debug("Order {$order->getIncrementId()} can not be held because {$reason}");

                    $orderAction['action'] = false;
                }
                break;

            case "unhold":
                if ($order->canUnhold()) {
                    $this->_logger->debug('Unhold order action');
                    try{
                        $order->unhold();

                        $completeCase = true;
                    } catch (\Exception $e){
                        $this->_logger->debug($e->__toString());
                        $orderAction['action'] = false;
                    }
                } else {
                    if ($order->getState() != Order::STATE_HOLDED && $order->isPaymentReview() == false) {
                        $reason = "order is not holded";
                        $completeCase = true;
                    } elseif ($order->isPaymentReview()) {
                        $reason = 'order is in payment review';
                    } elseif ($order->getActionFlag(Order::ACTION_FLAG_UNHOLD) === false) {
                        $reason = "order action flag is set to do not unhold";
                    } else {
                        $reason = "unknown reason";
                    }

                    $this->_logger->debug(
                        "Order {$order->getIncrementId()} ({$order->getState()} > {$order->getStatus()}) " .
                        "can not be removed from hold because {$reason}. " .
                        "Case status: {$case->getSignifydStatus()}"
                    );

                    $orderAction['action'] = false;
                }
                break;

            case "cancel":
                if ($order->canUnhold()) {
                    $order = $order->unhold();
                }

                if ($order->canCancel()) {
                    try {
                        $order->cancel();
                        $order->addStatusHistoryComment('Signifyd: order canceled');
                        $completeCase = true;
                    } catch (\Exception $e) {
                        $this->_logger->debug($e->__toString());
                        $order->addStatusHistoryComment('Signifyd: unable to cancel order: ' . $e->getMessage());
                        $orderAction['action'] = false;
                    }
                } else {
                    $notCancelableStates = [
                        Order::STATE_CANCELED,
                        Order::STATE_PAYMENT_REVIEW,
                        Order::STATE_COMPLETE,
                        Order::STATE_CLOSED,
                        Order::STATE_HOLDED
                    ];

                    if (in_array($order->getState(), $notCancelableStates)) {
                        $reason = "order is on {$order->getState()} state";
                    } elseif (!$order->canReviewPayment() && $order->canFetchPaymentReviewUpdate()) {
                        $reason = "payment review issues";
                    } elseif ($order->getActionFlag(Order::ACTION_FLAG_CANCEL) === false) {
                        $reason = "order action flag is set to do not cancel";
                    } else {
                        $allInvoiced = true;
                        foreach ($order->getAllItems() as $item) {
                            if ($item->getQtyToInvoice()) {
                                $allInvoiced = false;
                                break;
                            }
                        }
                        if ($allInvoiced) {
                            $reason = "all items are invoiced";
                            $completeCase = true;
                        } else {
                            $reason = "unknown reason";
                        }
                    }

                    $this->_logger->debug("Order {$order->getIncrementId()} can not be canceled because {$reason}");

                    $orderAction['action'] = false;
                }

                if ($orderAction['action'] == false && $order->canHold()) {
                    $order = $order->hold();
                }
                break;

            case "capture":
                try {
                    if ($order->canUnhold()) {
                        $order->unhold();
                    }

                    if ($order->canInvoice()) {
                        /** @var \Magento\Sales\Model\Order\Invoice $invoice */
                        $invoice = $this->invoiceService->prepareInvoice($order);

                        if ($invoice->isEmpty()) {
                            throw new \Exception('Failed to prepare invoice for order');
                        }

                        if ($invoice->getTotalQty() == 0) {
                            throw new \Exception('No items founded to be invoiced');
                        }

                        $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
                        $invoice->addComment('Signifyd: Automatic invoice');
                        $invoice->register();

                        $order->setCustomerNoteNotify(true);
                        $order->setIsInProcess(true);
                        $order->addStatusHistoryComment('Signifyd: Automatic invoice');

                        $transactionSave = $this->objectManager->create(
                            \Magento\Framework\DB\Transaction::class
                        )->addObject(
                            $invoice
                        )->addObject(
                            $order
                        );
                        $transactionSave->save();

                        // Avoid to save order agains, which trigger Magento's exception
                        $order->setDataChanges(false);

                        $this->_logger->debug('Invoice was created for order: ' . $order->getIncrementId());

                        // Send invoice email
                        try {
                            $this->invoiceSender->send($invoice);
                        } catch (\Exception $e) {
                            $this->_logger->debug('Failed to send the invoice email: ' . $e->getMessage());
                        }

                        $completeCase = true;
                    } else {
                        $notInvoiceableStates = [
                            Order::STATE_CANCELED,
                            Order::STATE_PAYMENT_REVIEW,
                            Order::STATE_COMPLETE,
                            Order::STATE_CLOSED,
                            Order::STATE_HOLDED
                        ];

                        if (in_array($order->getState(), $notInvoiceableStates)) {
                            $reason = "order is on {$order->getState()} state";
                        } elseif ($order->getActionFlag(self::ACTION_FLAG_INVOICE) === false) {
                            $reason = "order action flag is set to do not invoice";
                        } else {
                            foreach ($this->getAllItems() as $item) {
                                if ($item->getQtyToInvoice() > 0 && !$item->getLockedDoInvoice()) {
                                    return true;
                                }
                            }

                            $canInvoiceAny = false;

                            foreach ($order->getAllItems() as $item) {
                                if ($item->getQtyToInvoice() > 0 && !$item->getLockedDoInvoice()) {
                                    $canInvoiceAny = true;
                                    break;
                                }
                            }

                            if ($canInvoiceAny) {
                                $reason = "unknown reason";
                            } else {
                                $reason = "no items can be invoiced";
                                $completeCase = true;
                            }
                        }

                        $this->_logger->debug("Order {$order->getIncrementId()} can not be invoiced because {$reason}");

                        $orderAction['action'] = false;

                        if ($order->canHold()) {
                            $order->hold();
                        }
                    }
                } catch (\Exception $e) {
                    $this->_logger->debug('Exception while creating invoice: ' . $e->__toString());

                    if ($order->canHold()) {
                        $order->hold();
                    }

                    $order->addStatusHistoryComment('Signifyd: unable to create invoice: ' . $e->getMessage());

                    $orderAction['action'] = false;
                }

                break;

            // Nothing is an action from Signifyd workflow, different from when no action is given (null or empty)
            // If workflow is set to do nothing, so complete the case
            case 'nothing':
            case null:
                $orderAction['action'] = false;

                try {
                    $completeCase = true;
                } catch (\Exception $e) {
                    $this->_logger->debug($e->__toString());
                    return false;
                }
                break;
        }

        if ($orderAction['action'] != false) {
            $order->addStatusHistoryComment("Signifyd set status to {$orderAction["action"]} because {$orderAction["reason"]}");
        }

        if ($order->hasDataChanges()) {
            $order->getResource()->save($order);
        }

        if ($completeCase) {
            $case->setMagentoStatus(CaseRetry::COMPLETED_STATUS)
                ->setUpdated(strftime('%Y-%m-%d %H:%M:%S', time()));
            $case->getResource()->save($case);
        }

        return true;
    }

    /**
     * @param $caseData
     * @throws \Magento\Framework\Exception\LocalizedException
     * @return array
     */
    protected function handleStatusChange($caseData)
    {
        $holdBelowThreshold = $this->configHelper->getConfigData('signifyd/advanced/hold_orders', $this);

        if ($holdBelowThreshold && $caseData['request']->reviewDisposition == 'FRAUDULENT') {
            return array("action" => "hold", "reason" => "review returned FRAUDULENT");
        } else {
            if ($holdBelowThreshold && $caseData['request']->reviewDisposition == 'GOOD') {
                return array("action" => "unhold", "reason" => "review returned GOOD");
            }
        }
        return null;
    }

    /**
     * @param $caseData
     * @throws \Magento\Framework\Exception\LocalizedException
     * @return array
     */
    protected function handleGuaranteeChange($caseData)
    {
        if (!isset($caseData['case']) || !$caseData['case'] instanceof \Signifyd\Connect\Model\Casedata) {
            return null;
        }

        $negativeAction = $caseData['case']->getNegativeAction();
        $positiveAction = $caseData['case']->getPositiveAction();

        $this->_logger->debug("Signifyd: Positive action for {$caseData['case']->getOrderIncrement()}: " . $positiveAction);
        $request = $caseData['request'];
        switch ($request->guaranteeDisposition){
            case "DECLINED":
                return array("action" => $negativeAction, "reason" => "guarantee declined");
                break;
            case "APPROVED":
                return array("action" => $positiveAction, "reason" => "guarantee approved");
                break;
            default:
                $this->_logger->debug("Signifyd: Unknown guaranty: " . $request->guaranteeDisposition);
                break;
        }

        return null;
    }

    /**
     * @param null $index
     * @return array|mixed|null
     */
    public function getEntries($index = null)
    {
        $entries = $this->getData('entries_text');

        if (!empty($entries)) {
            @$entries = unserialize($entries);
        }

        if (!is_array($entries)) {
            $entries = array();
        }

        if (!empty($index)) {
            return isset($entries[$index]) ? $entries[$index] : null;
        }

        return $entries;
    }

    public function setEntries($index, $value = null)
    {
        if (is_array($index)) {
            $entries = $index;
        } elseif (is_string($index)) {
            $entries = $this->getEntries();
            $entries[$index] = $value;
        }

        @$entries = serialize($entries);
        $this->setData('entries_text', $entries);

        return $this;
    }

    public function isHoldReleased()
    {
        $holdReleased = $this->getEntries('hold_released');
        return ($holdReleased == 1) ? true : false;
    }

    public function getPositiveAction()
    {
        if ($this->isHoldReleased()) {
            return 'nothing';
        } else {
            return $this->configHelper->getConfigData('signifyd/advanced/guarantee_positive_action', $this);
        }
    }

    public function getNegativeAction()
    {
        if ($this->isHoldReleased()) {
            return 'nothing';
        } else {
            return $this->configHelper->getConfigData('signifyd/advanced/guarantee_negative_action', $this);
        }
    }

    /**
     * Everytime a update is triggered reset retries
     *
     * @param $updated
     * @return mixed
     */
    public function setUpdated($updated)
    {
        $this->setRetries(0);

        return parent::setUpdated($updated);
    }

    /**
     * @param $caseData
     * @throws \Magento\Framework\Exception\LocalizedException
     * @return string
     */
    protected function handleScoreChange($caseData)
    {
        $threshHold = (int) $this->configHelper->getConfigData('signifyd/advanced/hold_orders_threshold', $this);
        $holdBelowThreshold = $this->configHelper->getConfigData('signifyd/advanced/hold_orders', $this);
        if ($holdBelowThreshold && $caseData['request']->score <= $threshHold) {
            return array("action" => "hold", "reason" => "score threshold failure");
        }
        return null;
    }

}

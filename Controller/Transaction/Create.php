<?php
 
namespace Transbank\Onepay\Controller\Transaction;

use \Transbank\Onepay\OnepayBase;
use \Transbank\Onepay\ShoppingCart;
use \Transbank\Onepay\Item;
use \Transbank\Onepay\Transaction;
use \Transbank\Onepay\Options;
use \Transbank\Onepay\Exceptions\TransactionCreateException;
use \Transbank\Onepay\Exceptions\TransbankException;
use \Transbank\Onepay\Model\Onepay;

use \Magento\Sales\Model\Order;

/**
 * Controller for create transaction Onepay
 */
class Create extends \Magento\Framework\App\Action\Action {

    public function __construct(\Magento\Framework\App\Action\Context $context,
                                \Magento\Checkout\Model\Cart $cart,
                                \Magento\Checkout\Model\Session $checkoutSession,
                                \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory,
                                \Magento\Quote\Model\QuoteManagement $quoteManagement,
                                \Transbank\Onepay\Model\Config\ConfigProvider $configProvider,
                                \Transbank\Onepay\Model\CustomLogger $log) {

        parent::__construct($context);

        $this->_cart = $cart;
        $this->_checkoutSession = $checkoutSession;
        $this->_resultJsonFactory = $resultJsonFactory;
        $this->_quoteManagement = $quoteManagement;
        $this->_configProvider = $configProvider;
        $this->_log = $log;
    }
 
    public function execute() {

        $response = null;

        $channel = isset($_POST['channel']) ? $_POST['channel'] : null;

        if (isset($channel)) {

            try {

                $apiKey = $this->_configProvider->getApiKey();
                $sharedSecret = $this->_configProvider->getSharedSecret();
                $environment = $this->_configProvider->getEnvironment();

                OnepayBase::setApiKey($apiKey);
                OnepayBase::setSharedSecret($sharedSecret);
                OnepayBase::setCurrentIntegrationType($environment);

                $options = new Options($apiKey, $sharedSecret);

                if ($environment == 'LIVE') {
                    $options->setAppKey('F43FDB87-32BB-4184-AA46-40EA62A8E9F3');
                }

                $orderStatusPendingPayment = Order::STATE_PENDING_PAYMENT;

                $tmpOrder = $this->getOrder();

                if ($tmpOrder != null && $tmpOrder->getStatus() == $orderStatusPendingPayment) {
                    $orderStatusCanceled = Order::STATE_CANCELED;
                    $tmpOrder->setState($orderStatusCanceled)->setStatus($orderStatusCanceled);
                    $tmpOrder->save();
                    $this->_checkoutSession->restoreQuote();
                }

                $quote = $this->_cart->getQuote();
                $quote->getPayment()->importData(['method' => Onepay::CODE]);
                $quote->collectTotals()->save();
                $order = $this->_quoteManagement->submit($quote);

                $order->setState($orderStatusPendingPayment)->setStatus($orderStatusPendingPayment);
                $order->save();

                $this->_checkoutSession->setLastQuoteId($quote->getId());
                $this->_checkoutSession->setLastSuccessQuoteId($quote->getId());
                $this->_checkoutSession->setLastOrderId($order->getId());
                $this->_checkoutSession->setLastRealOrderId($order->getIncrementId());
                $this->_checkoutSession->setLastOrderStatus($order->getStatus());
                $this->_checkoutSession->setGrandTotal(round($quote->getGrandTotal()));

                $carro = new ShoppingCart();

                $items = $quote->getAllVisibleItems();

                foreach($items as $qItem) {
                    $item = new Item($qItem->getName(), intval($qItem->getQty()), intval($qItem->getPriceInclTax()));
                    $carro->add($item);
                }

                $shippingAmount = $quote->getShippingAddress()->getShippingAmount();

                if ($shippingAmount != 0) {
                    $item = new Item("Costo por envio", 1, intval($shippingAmount));
                    $carro->add($item);
                }

                $dataLog = array('quote_id' => $this->_checkoutSession->getLastQuoteId(),
                                 'order_id' => $this->_checkoutSession->getLastOrderId(),
                                 'order_increment_id' => $this->_checkoutSession->getLastRealOrderId(),
                                 'grand_total' => $this->_checkoutSession->getGrandTotal()
                                );

                $this->_log->info('Creando transaccion: ' . json_encode($dataLog));

                $transaction = Transaction::create($carro, $channel, $options);

                $amount = $carro->getTotal();
                $occ = $transaction->getOcc();
                $ott = $transaction->getOtt();
                $externalUniqueNumber = $transaction->getExternalUniqueNumber();
                $issuedAt = $transaction->getIssuedAt();
                $dateTransaction = date('Y-m-d H:i:s', $issuedAt);

                $message = "<h3>Esperando pago con Onepay:</h3>
                            <br><b>Fecha de Transacci&oacute;n:</b> {$dateTransaction}
                            <br><b>OCC:</b> {$occ}
                            <br><b>N&uacute;mero de carro:</b> {$externalUniqueNumber}
                            <br><b>Monto de la Compra:</b> {$amount}";

                $order->addStatusToHistory($order->getStatus(), $message);
                $order->save();

                $response = array(
                    'externalUniqueNumber' => $externalUniqueNumber,
                    'amount' => $amount,
                    'qrCodeAsBase64' => $transaction->getQrCodeAsBase64(),
                    'issuedAt' => $issuedAt,
                    'occ' => $occ,
                    'ott' => $ott
                );

                $this->_checkoutSession->getQuote()->setIsActive(true)->save();
                $this->_cart->getQuote()->setIsActive(true)->save();

            } catch (TransbankException $transbank_exception) {
                $msg = 'Creacion de transacción fallida: ' . $transbank_exception->getMessage();
                $this->_log->error($msg);
                throw new TransactionCreateException($msg);
            }

        } else {
            $msg = 'Falta parámetro channel';
            $this->_log->error($msg);
            $response = array('error' => $msg);
        }

        $result = $this->_resultJsonFactory->create();
        $result->setData($response);
        return $result;   
    }

    private function getOrder() {
        try {
            $orderId = $this->_checkoutSession->getLastOrderId();
            if ($orderId == null) {
                return null;
            }
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            return $objectManager->create('\Magento\Sales\Model\Order')->load($orderId);
        } catch (Exception $e) {
            return null;
        }
    }
}
<?php
 
namespace Transbank\Onepay\Controller\Transaction;

use Transbank\Onepay\OnepayBase;
use Transbank\Onepay\ShoppingCart;
use Transbank\Onepay\Item;
use Transbank\Onepay\Transaction;
use \Transbank\Onepay\Exceptions\TransactionCreateException;
use \Transbank\Onepay\Exceptions\TransbankException;

use \Transbank\Onepay\Model\Config\ConfigProvider;

use \Magento\Sales\Model\Order;

/**
 * Controller for commit transaction Onepay
 */
class Commit extends \Magento\Framework\App\Action\Action {

    public function __construct(\Magento\Framework\App\Action\Context $context,
                                \Magento\Checkout\Model\Cart $cart,
                                \Magento\Checkout\Model\Session $checkoutSession,
                                \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
                                \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory) {


        parent::__construct($context);
        
        $this->_cart = $cart;
        $this->_checkoutSession = $checkoutSession;
        $this->_scopeConfig = $scopeConfig;
        $this->_resultJsonFactory = $resultJsonFactory;
        $this->_messageManager = $context->getMessageManager();
    }
 
    public function execute() {

        $orderStatusComplete = Order::STATE_COMPLETE;
        $orderStatusCanceled = Order::STATE_CANCELED;
        $orderStatusRejected = Order::STATE_CLOSED;

        $status = isset($_GET['status']) ? $_GET['status'] : null;
        $occ = isset($_GET['occ']) ? $_GET['occ'] : null;
        $externalUniqueNumber = isset($_GET['externalUniqueNumber']) ? $_GET['externalUniqueNumber'] : null;

        if ($status == null || $occ == null || $externalUniqueNumber == null) {
            return $this->fail($orderStatusCanceled, 'Parametros inválidos');
        }

        if ($status == 'PRE_AUTHORIZED') {
            try {

                $configProvider = new ConfigProvider($this->_scopeConfig);
                $apiKey = $configProvider->getApiKey();
                $sharedSecret = $configProvider->getSharedSecret();
                $environment = $configProvider->getEnvironment();

                OnepayBase::setApiKey($apiKey);
                OnepayBase::setSharedSecret($sharedSecret);
                OnepayBase::setCurrentIntegrationType($environment);

                $transactionCommitResponse = Transaction::commit($occ, $externalUniqueNumber);

                if ($transactionCommitResponse->getResponseCode() == 'OK') {

                    $amount = $transactionCommitResponse->getAmount();
                    $buyOrder = $transactionCommitResponse->getBuyOrder();
                    $authorizationCode = $transactionCommitResponse->getAuthorizationCode();
                    $description = $transactionCommitResponse->getDescription();
                    $issuedAt = $transactionCommitResponse->getIssuedAt();
                    $dateTransaction = date('Y-m-d H:i:s', $issuedAt);

                    $message = "<h3>Detalles del pago con Onepay:</h3>
                                <br><b>Fecha de Transacci&oacute;n:</b> {$dateTransaction}
                                <br><b>OCC:</b> {$occ}
                                <br><b>N&uacute;mero de carro:</b> {$externalUniqueNumber}
                                <br><b>C&oacute;digo de Autorizaci&oacute;n:</b> {$authorizationCode}
                                <br><b>Orden de Compra:</b> {$buyOrder}
                                <br><b>Estado:</b> {$description}
                                <br><b>Monto de la Compra:</b> {$amount}";

                    $installmentsNumber = $transactionCommitResponse->getInstallmentsNumber();

                    if ($installmentsNumber == 1) {

                        $message = $message . "<br><b>N&uacute;mero de cuotas:</b> Sin cuotas";

                    } else {

                        $installmentsAmount = $transactionCommitResponse->getInstallmentsAmount();

                        $message = $message . "<br><b>N&uacute;mero de cuotas:</b> {$installmentsNumber}
                                               <br><b>Monto cuota:</b> {$installmentsAmount}";
                    }

                    return $this->success($orderStatusComplete, $message);//'Tu pago se ha realizado exitosamente');
                } else {
                    return $this->fail($orderStatusRejected, 'Tu pago ha fallado. Vuelve a intentarlo más tarde.');
                }

            } catch (TransbankException $transbank_exception) {
                return $this->fail($orderStatusRejected, 'Error en el servicio de pago. Vuelve a intentarlo más tarde.');
            }
        } else if($status == 'REJECTED') {
            return $this->fail($orderStatusRejected, 'Tu pago ha fallado. Pago rechazado');
        } else {
            return $this->fail($orderStatusCanceled, 'Tu pago ha fallado. Compra cancelada');
        }
    }

    private function success($orderStatus, $message) {
        $order = $this->getOrder();
        $order->setState($orderStatus)->setStatus($orderStatus);
        $order->addStatusToHistory($order->getStatus(), $message);
        $order->save();
        $this->_messageManager->addSuccess(__($message));
        $this->_checkoutSession->getQuote()->setIsActive(false)->save();
        return $this->resultRedirectFactory->create()->setPath('checkout/onepage/success');
    }

    private function fail($orderStatus, $message) {
        $order = $this->getOrder();
        $order->setState($orderStatus)->setStatus($orderStatus);
        $order->addStatusToHistory($order->getStatus(), $message);
        $order->save();
        $this->_messageManager->addError(__($message));
        $this->_checkoutSession->restoreQuote();
        return $this->resultRedirectFactory->create()->setPath('checkout/cart');
    }

    private function getOrder() {
        $orderId = $this->_checkoutSession->getLastOrderId();
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        return $objectManager->create('\Magento\Sales\Model\Order')->load($orderId);
    }
}
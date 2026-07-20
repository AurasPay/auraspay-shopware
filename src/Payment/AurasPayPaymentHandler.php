<?php declare(strict_types=1);

namespace AurasPay\Shopware\Payment;

use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

final class AurasPayPaymentHandler extends AbstractPaymentHandler
{
    private const CONFIG_PREFIX = 'AurasPayShopware.config.';

    public function __construct(
        private readonly RouterInterface $router,
        private readonly SystemConfigService $config,
    ) {
    }

    public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
    {
        return false;
    }

    public function pay(Request $request, PaymentTransactionStruct $transaction, Context $context, ?Struct $validateStruct): ?RedirectResponse
    {
        $apiKey = trim((string) $this->config->get(self::CONFIG_PREFIX . 'apiKey'));
        $webhookSecret = trim((string) $this->config->get(self::CONFIG_PREFIX . 'webhookSecret'));
        if ($apiKey === '' || $webhookSecret === '') {
            throw PaymentException::asyncProcessInterrupted(
                $transaction->getOrderTransactionId(),
                'AURAS Pay API key or webhook secret is not configured.'
            );
        }

        $returnUrl = (string) $transaction->getReturnUrl();
        $transactionId = $transaction->getOrderTransactionId();
        $token = hash_hmac('sha256', $transactionId . '|' . $returnUrl, $webhookSecret);

        return new RedirectResponse($this->router->generate('frontend.auraspay.select', [
            'transactionId' => $transactionId,
            'returnUrl' => $returnUrl,
            'token' => $token,
        ], UrlGeneratorInterface::ABSOLUTE_URL));
    }
}

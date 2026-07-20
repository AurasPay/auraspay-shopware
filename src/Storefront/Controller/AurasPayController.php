<?php declare(strict_types=1);

namespace AurasPay\Shopware\Storefront\Controller;

use AurasPay\Shopware\Service\AurasPayClient;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Routing\StoreApiRouteScope;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route(defaults: ['_routeScope' => [StorefrontRouteScope::ID]])]
final class AurasPayController extends StorefrontController
{
    private const CONFIG_PREFIX = 'AurasPayShopware.config.';
    private const ASSETS = [
        'USDC' => ['solana', 'ethereum', 'bnb'],
        'USDT' => ['solana', 'ethereum', 'tron', 'bnb'],
        'SOL' => ['solana'],
        'ETH' => ['ethereum'],
        'BTC' => ['bitcoin'],
        'TRX' => ['tron'],
        'BNB' => ['bnb'],
    ];

    public function __construct(
        private readonly AurasPayClient $client,
        private readonly SystemConfigService $config,
        private readonly EntityRepository $transactionRepository,
        private readonly OrderTransactionStateHandler $stateHandler,
    ) {
    }

    #[Route(path: '/auraspay/select/{transactionId}', name: 'frontend.auraspay.select', methods: ['GET', 'POST'])]
    public function select(Request $request, string $transactionId, SalesChannelContext $salesChannelContext): Response
    {
        $returnUrl = $request->query->getString('returnUrl', $request->request->getString('returnUrl'));
        $token = $request->query->getString('token', $request->request->getString('token'));
        $webhookSecret = trim((string) $this->config->get(self::CONFIG_PREFIX . 'webhookSecret'));
        $expected = hash_hmac('sha256', $transactionId . '|' . $returnUrl, $webhookSecret);
        if ($webhookSecret === '' || !hash_equals($expected, $token)) {
            return new Response('Invalid AURAS Pay checkout token.', Response::HTTP_FORBIDDEN);
        }

        $transaction = $this->loadTransaction($transactionId, $salesChannelContext->getContext());
        if (!$transaction instanceof OrderTransactionEntity || $transaction->getOrder() === null) {
            return new Response('Shopware transaction not found.', Response::HTTP_NOT_FOUND);
        }

        if ($request->isMethod('POST')) {
            try {
                $currency = strtoupper($request->request->getString('currency'));
                $network = strtolower($request->request->getString('network'));
                if (!isset(self::ASSETS[$currency]) || !in_array($network, self::ASSETS[$currency], true)) {
                    throw new \InvalidArgumentException('Unsupported currency and network combination.');
                }

                $apiKey = trim((string) $this->config->get(self::CONFIG_PREFIX . 'apiKey'));
                if ($apiKey === '') {
                    throw new \RuntimeException('AURAS Pay API key is not configured.');
                }

                $order = $transaction->getOrder();
                $shopCurrency = $order->getCurrency()?->getIsoCode();
                if ($shopCurrency === null) {
                    throw new \RuntimeException('Shopware order currency is unavailable.');
                }
                $reference = 'SW-' . $order->getOrderNumber() . '-' . substr($transactionId, 0, 8);
                $payment = $this->client->createPayment($apiKey, [
                    'fiatAmount' => round($transaction->getAmount()->getTotalPrice(), 2),
                    'fiatCurrency' => strtoupper($shopCurrency),
                    'currency' => $currency,
                    'network' => $network,
                    'reference' => $reference,
                    'webhookUrl' => $this->generateUrl('store-api.auraspay.webhook', [], UrlGeneratorInterface::ABSOLUTE_URL),
                    'label' => 'Shopware order ' . $order->getOrderNumber(),
                    'message' => 'Payment for Shopware order ' . $order->getOrderNumber(),
                ]);
                $paymentId = (string) ($payment['id'] ?? '');
                $checkoutUrl = (string) ($payment['publicUrl'] ?? $payment['checkoutUrl'] ?? '');
                if ($paymentId === '' || !str_starts_with($checkoutUrl, 'https://')) {
                    throw new \RuntimeException('AURAS Pay did not return a valid checkout URL.');
                }

                $this->transactionRepository->update([[
                    'id' => $transactionId,
                    'customFields' => array_merge($transaction->getCustomFields() ?? [], [
                        'auraspay_payment_id' => $paymentId,
                        'auraspay_reference' => $reference,
                        'auraspay_currency' => $currency,
                        'auraspay_network' => $network,
                        'auraspay_fiat_amount' => round($transaction->getAmount()->getTotalPrice(), 2),
                        'auraspay_fiat_currency' => strtoupper($shopCurrency),
                    ]),
                ]], $salesChannelContext->getContext());
                $this->stateHandler->processUnconfirmed($transactionId, $salesChannelContext->getContext());

                return new RedirectResponse($checkoutUrl);
            } catch (\Throwable $exception) {
                $response = $this->renderStorefront('@AurasPayShopware/storefront/page/auraspay/select.html.twig', [
                    'assets' => self::ASSETS,
                    'transactionId' => $transactionId,
                    'returnUrl' => $returnUrl,
                    'token' => $token,
                    'error' => $exception->getMessage(),
                ]);
                $response->setStatusCode(Response::HTTP_BAD_REQUEST);
                return $response;
            }
        }

        return $this->renderStorefront('@AurasPayShopware/storefront/page/auraspay/select.html.twig', [
            'assets' => self::ASSETS,
            'transactionId' => $transactionId,
            'returnUrl' => $returnUrl,
            'token' => $token,
        ]);
    }

    #[Route(path: '/store-api/auraspay/webhook', name: 'store-api.auraspay.webhook', defaults: ['_routeScope' => [StoreApiRouteScope::ID], 'auth_required' => false], methods: ['POST'])]
    public function webhook(Request $request): JsonResponse
    {
        $secret = trim((string) $this->config->get(self::CONFIG_PREFIX . 'webhookSecret'));
        $raw = $request->getContent();
        $received = $request->headers->get('X-AURAS-Signature', '');
        $received = str_starts_with($received, 'sha256=') ? substr($received, 7) : $received;
        if ($secret === '' || !hash_equals(hash_hmac('sha256', $raw, $secret), $received)) {
            return new JsonResponse(['received' => false], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $event = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['received' => false], Response::HTTP_BAD_REQUEST);
        }
        $payment = $event['data']['payment'] ?? [];
        $paymentId = (string) ($payment['id'] ?? '');
        if ($paymentId === '') {
            return new JsonResponse(['received' => false], Response::HTTP_BAD_REQUEST);
        }

        $context = Context::createDefaultContext();
        $criteria = (new Criteria())->addFilter(new EqualsFilter('customFields.auraspay_payment_id', $paymentId));
        $criteria->addAssociation('stateMachineState');
        $transaction = $this->transactionRepository->search($criteria, $context)->first();
        if (!$transaction instanceof OrderTransactionEntity) {
            return new JsonResponse(['received' => false], Response::HTTP_NOT_FOUND);
        }

        $eventType = (string) ($event['event_type'] ?? '');
        if ($eventType === 'payment.completed' && $transaction->getStateMachineState()?->getTechnicalName() !== 'paid') {
            $apiKey = trim((string) $this->config->get(self::CONFIG_PREFIX . 'apiKey'));
            $verified = $this->client->getPayment($apiKey, $paymentId);
            $custom = $transaction->getCustomFields() ?? [];
            $valid = strtoupper((string) ($verified['status'] ?? '')) === 'COMPLETED'
                && (string) ($verified['id'] ?? '') === $paymentId
                && (string) ($verified['reference'] ?? '') === (string) ($custom['auraspay_reference'] ?? '')
                && strtoupper((string) ($verified['currency'] ?? '')) === (string) ($custom['auraspay_currency'] ?? '')
                && strtolower((string) ($verified['network'] ?? '')) === (string) ($custom['auraspay_network'] ?? '')
                && strtoupper((string) ($verified['fiatCurrency'] ?? '')) === (string) ($custom['auraspay_fiat_currency'] ?? '')
                && abs((float) ($verified['fiatAmount'] ?? -1) - (float) ($custom['auraspay_fiat_amount'] ?? -2)) < 0.01;
            if (!$valid) {
                return new JsonResponse(['received' => false, 'error' => 'Payment verification failed.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $this->stateHandler->paid($transaction->getId(), $context);
        } elseif ($eventType === 'payment.failed' && $transaction->getStateMachineState()?->getTechnicalName() !== 'failed') {
            $this->stateHandler->fail($transaction->getId(), $context);
        }

        return new JsonResponse(['received' => true]);
    }

    private function loadTransaction(string $id, Context $context): ?OrderTransactionEntity
    {
        $criteria = new Criteria([$id]);
        $criteria->addAssociation('order.currency');
        $criteria->addAssociation('stateMachineState');
        $entity = $this->transactionRepository->search($criteria, $context)->first();
        return $entity instanceof OrderTransactionEntity ? $entity : null;
    }
}

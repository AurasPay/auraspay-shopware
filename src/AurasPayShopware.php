<?php declare(strict_types=1);

namespace AurasPay\Shopware;

use AurasPay\Shopware\Payment\AurasPayPaymentHandler;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;

final class AurasPayShopware extends Plugin
{
    public const TECHNICAL_NAME = 'auraspay_crypto';

    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);

        $repository = $this->container->get('payment_method.repository');
        $criteria = (new Criteria())->addFilter(new EqualsFilter('handlerIdentifier', AurasPayPaymentHandler::class));
        if ($repository->searchIds($criteria, $installContext->getContext())->getTotal() > 0) {
            return;
        }

        $repository->create([[
            'handlerIdentifier' => AurasPayPaymentHandler::class,
            'technicalName' => self::TECHNICAL_NAME,
            'active' => true,
            'position' => 1,
            'afterOrderEnabled' => false,
            'translations' => [
                'en-GB' => [
                    'name' => 'AURAS Pay (Crypto)',
                    'description' => 'Pay securely with cryptocurrency through AURAS Pay.',
                ],
                'de-DE' => [
                    'name' => 'AURAS Pay (Krypto)',
                    'description' => 'Sicher mit Kryptowährung über AURAS Pay bezahlen.',
                ],
            ],
        ]], $installContext->getContext());
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);
        if ($uninstallContext->keepUserData()) {
            return;
        }

        $repository = $this->container->get('payment_method.repository');
        $criteria = (new Criteria())->addFilter(new EqualsFilter('handlerIdentifier', AurasPayPaymentHandler::class));
        $ids = $repository->searchIds($criteria, $uninstallContext->getContext())->getIds();
        if ($ids !== []) {
            $repository->delete(array_map(static fn (string $id): array => ['id' => $id], $ids), $uninstallContext->getContext());
        }
    }
}

<?php

declare(strict_types=1);

namespace FriendsOfSylius\SyliusImportExportPlugin\Exporter\Transformer\Handler;

use FriendsOfSylius\SyliusImportExportPlugin\Exporter\Transformer\Handler;

final class IntegerToMoneyFormatHandler extends Handler
{
    /** @var array */
    private $keys;

    /** @var string */
    private $format;

    /**
     * @param string[] $allowedKeys
     */
    public function __construct(array $allowedKeys, string $format = '%.2f')
    {
        $this->keys = $allowedKeys;
        $this->format = $format;
    }

    /**
     * {@inheritdoc}
     */
    protected function process($key, $value): ?string
    {
		$locale = '';
		if(isset($GLOBALS['request']) && $GLOBALS['request']) {
			$locale = $GLOBALS['request']->getLocale();
		}
		$fmt = new \NumberFormatter( $locale, \NumberFormatter::CURRENCY );
		return $fmt->formatCurrency($value/100, "USD");
        //return sprintf($this->format, $value / 100);
    }

    protected function allows($key, $value): bool
    {
        return is_int($value) && in_array($key, $this->keys);
    }
}

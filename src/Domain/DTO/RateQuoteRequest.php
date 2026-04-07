<?php

namespace Lilleprinsen\Cargonizer\Domain\DTO;

final class RateQuoteRequest
{
    private string $agreementId;
    private string $productId;

    /** @var array<string,mixed> */
    private array $package;

    /**
     * @param array<string,mixed> $package
     */
    public function __construct(string $agreementId, string $productId, array $package)
    {
        $this->agreementId = $agreementId;
        $this->productId = $productId;
        $this->package = $package;
    }

    public function getAgreementId(): string
    {
        return $this->agreementId;
    }

    public function getProductId(): string
    {
        return $this->productId;
    }

    /**
     * @return array<string,mixed>
     */
    public function getPackage(): array
    {
        return $this->package;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'agreement_id' => $this->agreementId,
            'product_id' => $this->productId,
            'package' => $this->package,
        ];
    }
}

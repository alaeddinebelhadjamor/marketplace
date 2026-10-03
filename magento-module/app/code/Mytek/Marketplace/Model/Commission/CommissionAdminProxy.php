<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Model\Commission;

use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\Phrase;
use Mytek\Marketplace\Model\Api\ApiException;
use Mytek\Marketplace\Model\Api\Client;
use Mytek\Marketplace\Model\Api\Response;

/**
 * Actions qui changent un état côté commissions : relais vers l'API v2, qui porte seule la
 * logique de génération des relevés (idempotente, cf. CommissionService::generateStatement)
 * et le rendu PDF (DomPDF). Le module ne duplique pas cette logique métier.
 */
class CommissionAdminProxy
{
    public function __construct(private readonly Client $client)
    {
    }

    /** @return int nombre de relevés générés ou mis à jour */
    public function generateStatements(string $period): int
    {
        $response = $this->client->post('api/admin/statements/generate', ['period' => $period]);
        $this->assertSuccess($response, __('Could not generate the payout statements'));
        return (int)($response->json()['statements'] ?? 0);
    }

    /** @param ?float $rate null pour revenir au taux par défaut */
    public function setRate(int $sellerId, ?float $rate): void
    {
        $response = $this->client->put("api/admin/sellers/{$sellerId}/commission-rate", ['rate' => $rate]);
        $this->assertSuccess($response, __('Could not update the commission rate'));
    }

    /**
     * @throws NotFoundException  relevé introuvable
     * @throws ApiException       API injoignable ou en erreur
     */
    public function statementPdf(int $statementId): Response
    {
        $response = $this->client->get("api/admin/statements/{$statementId}/pdf");
        if ($response->status === 404) {
            throw new NotFoundException(__('Statement not found.'));
        }
        $this->assertSuccess($response, __('Could not download the statement'));
        return $response;
    }

    private function assertSuccess(Response $response, Phrase $context): void
    {
        if ($response->isSuccessful()) {
            return;
        }
        $detail = $response->errorMessage();
        throw new ApiException(
            $detail !== ''
                ? __('%1: %2', $context, $detail)
                : __('%1 (HTTP %2).', $context, $response->status)
        );
    }
}

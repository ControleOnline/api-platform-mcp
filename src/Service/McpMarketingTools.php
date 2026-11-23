<?php

declare(strict_types=1);

namespace ControleOnline\Service;

/**
 * Google Marketing tools for the MCP chat. Schema + dispatchers kept here so
 * McpBusinessTools stays focused on business queries. Guarded by a provider
 * that the host app wires only when api-platform-integration is available.
 */
trait McpMarketingTools
{
    public function canMarketing(): bool
    {
        return $this->marketingProvider !== null;
    }

    /** @return list<array<string, mixed>> */
    public static function marketingDefinitions(bool $includeMutations = true): array
    {
        $company = ['company_id' => ['type' => 'integer', 'minimum' => 1]];
        $period = [
            'start_date' => ['type' => 'string', 'description' => 'AAAA-MM-DD ou alias GA4 (ex.: "30daysAgo").'],
            'end_date' => ['type' => 'string', 'description' => 'AAAA-MM-DD ou "today".'],
        ];

        $definitions = [
            [
                'name' => 'marketing_connect_status',
                'description' => 'Retorna quais integrações do Google (analytics, ads, adsense) estão conectadas para a empresa.',
                'inputSchema' => ['type' => 'object', 'properties' => $company, 'required' => ['company_id'], 'additionalProperties' => false],
            ],
            [
                'name' => 'marketing_ga4_report',
                'description' => 'Consulta sessões, usuários e conversões do GA4 por período. Use metrics/dimensions para refinar.',
                'inputSchema' => ['type' => 'object', 'properties' => $company + $period + [
                    'metrics' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'dimensions' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000],
                ], 'required' => ['company_id'], 'additionalProperties' => false],
            ],
            [
                'name' => 'marketing_ads_campaigns',
                'description' => 'Lista campanhas Google Ads da empresa com status, canal e orçamento.',
                'inputSchema' => ['type' => 'object', 'properties' => $company, 'required' => ['company_id'], 'additionalProperties' => false],
            ],
            [
                'name' => 'marketing_ads_report',
                'description' => 'Relatório Google Ads por período: impressões, cliques, CTR, custo e conversões.',
                'inputSchema' => ['type' => 'object', 'properties' => $company + $period + [
                    'segment' => ['type' => 'string', 'enum' => ['campaign', 'date', 'device']],
                ], 'required' => ['company_id'], 'additionalProperties' => false],
            ],
            [
                'name' => 'marketing_adsense_report',
                'description' => 'Receita, RPM, impressões e cliques do AdSense por período e dimensão.',
                'inputSchema' => ['type' => 'object', 'properties' => $company + $period + [
                    'dimensions' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'metrics' => ['type' => 'array', 'items' => ['type' => 'string']],
                ], 'required' => ['company_id'], 'additionalProperties' => false],
            ],
        ];

        if ($includeMutations) {
            $definitions[] = [
                'name' => 'marketing_ads_pause',
                'description' => 'Pausa uma campanha Google Ads (status PAUSED).',
                'inputSchema' => ['type' => 'object', 'properties' => $company + ['campaign_id' => ['type' => 'string', 'minLength' => 1]], 'required' => ['company_id', 'campaign_id'], 'additionalProperties' => false],
            ];
            $definitions[] = [
                'name' => 'marketing_ads_resume',
                'description' => 'Reativa uma campanha Google Ads (status ENABLED).',
                'inputSchema' => ['type' => 'object', 'properties' => $company + ['campaign_id' => ['type' => 'string', 'minLength' => 1]], 'required' => ['company_id', 'campaign_id'], 'additionalProperties' => false],
            ];
            $definitions[] = [
                'name' => 'marketing_ads_update_budget',
                'description' => 'Atualiza o orçamento diário de uma campanha. Variações acima de +50% sem force=true devolvem requires_confirmation para você pedir confirmação ao usuário antes.',
                'inputSchema' => ['type' => 'object', 'properties' => $company + [
                    'campaign_id' => ['type' => 'string', 'minLength' => 1],
                    'amount_micros' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Orçamento em micros (R$ 1,00 = 1_000_000).'],
                    'force' => ['type' => 'boolean', 'description' => 'Enviar true após confirmação do usuário quando a variação exceder +50%.'],
                ], 'required' => ['company_id', 'campaign_id', 'amount_micros'], 'additionalProperties' => false],
            ];
        }

        return $definitions;
    }

    private function marketing(): McpMarketingProviderInterface
    {
        return $this->marketingProvider ?? throw new \RuntimeException('Marketing tools are not configured');
    }

    private function marketingCompanyId(array $arguments): int
    {
        $id = $arguments['company_id'] ?? null;
        if (!is_int($id) || $id < 1) {
            throw new \InvalidArgumentException('company_id is required');
        }
        return $id;
    }

    private function marketingCampaignId(array $arguments): string
    {
        $id = $arguments['campaign_id'] ?? null;
        if (!is_string($id) || trim($id) === '') {
            throw new \InvalidArgumentException('campaign_id is required');
        }
        return trim($id);
    }

    /** @return array<string, mixed> */
    private function marketingReportArgs(array $arguments): array
    {
        $out = [];
        foreach (['start_date' => 'startDate', 'end_date' => 'endDate', 'segment' => 'segment'] as $input => $output) {
            if (isset($arguments[$input]) && is_string($arguments[$input])) {
                $out[$output] = $arguments[$input];
            }
        }
        foreach (['metrics', 'dimensions'] as $key) {
            if (isset($arguments[$key]) && is_array($arguments[$key])) {
                $out[$key] = array_values(array_filter($arguments[$key], 'is_string'));
            }
        }
        if (isset($arguments['limit']) && is_int($arguments['limit'])) {
            $out['limit'] = $arguments['limit'];
        }
        return $out;
    }

    private function marketingAmountMicros(array $arguments): int
    {
        $amount = $arguments['amount_micros'] ?? null;
        if (!is_int($amount) || $amount < 0) {
            throw new \InvalidArgumentException('amount_micros must be a non-negative integer');
        }
        return $amount;
    }
}

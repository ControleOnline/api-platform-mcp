<?php

declare(strict_types=1);

namespace ControleOnline\Service;

use ControleOnline\Entity\Config;
use ControleOnline\Entity\Module;
use ControleOnline\Entity\People;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Company AI provider settings (configs.config_key = ai-providers).
 * API keys stay server-side; the UI only ever receives masked values.
 */
class McpChatProviderConfig
{
    public const CONFIG_KEY = 'ai-providers';
    /** Key inside the ai-providers JSON holding the provider /ai uses by default. */
    public const DEFAULT_KEY = '_default';

    /** Model ids that were shipped as defaults but are not valid API model ids. */
    private const LEGACY_MODEL_ALIASES = [
        'claude' => ['claude-sonnet-4' => 'claude-opus-5-5'],
        // Retired by Google ("no longer available").
        'gemini' => ['gemini-2.0-flash' => 'gemini-3.8-flash'],
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return array<string, array{label: string, base_url: string, model: string}> */
    public function defaultProviders(): array
    {
        return [
            'grok' => ['label' => 'Grok', 'base_url' => 'https://api.x.ai/v1', 'model' => 'grok-4'],
            'openai' => ['label' => 'Codex / OpenAI', 'base_url' => 'https://api.openai.com/v1', 'model' => 'gpt-4o'],
            'gemini' => ['label' => 'Gemini', 'base_url' => 'https://generativelanguage.googleapis.com/v1beta', 'model' => 'gemini-3.8-flash'],
            'claude' => ['label' => 'Claude', 'base_url' => 'https://api.anthropic.com/v1', 'model' => 'claude-opus-5-5'],
        ];
    }

    /**
     * Enabled provider with a key. When $key is null/unknown the first usable one is
     * returned (WhatsApp jobs may not name a provider).
     *
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    public function resolve(People $company, ?string $key): ?array
    {
        $all = $this->load($company);
        $key = $key !== null ? strtolower(trim($key)) : '';
        if ($key === '' || $key === 'auto') {
            $key = (string) $this->getDefault($company);
            if (!$this->isUsable($all[$key] ?? null)) {
                $key = '';
            }
        }
        if ($key !== '' && $this->isUsable($all[$key] ?? null)) {
            return [$key, $all[$key]];
        }
        if ($key !== '') {
            return null;
        }
        foreach ($all as $name => $cfg) {
            if ($this->isUsable($cfg)) {
                return [$name, $cfg];
            }
        }

        return null;
    }

    /**
     * The chosen provider first, then the company's other connected AIs (fallback when one is down).
     * Only this company's own AIs: a company never uses another company's AI.
     *
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    public function candidates(People $company, ?string $key): array
    {
        $first = $this->resolve($company, $key);
        if ($first === null) {
            return [];
        }
        $out = [$first];
        foreach ($this->load($company) as $name => $cfg) {
            if ($name !== $first[0] && $this->isUsable($cfg)) {
                $out[] = [$name, $cfg];
            }
        }

        return $out;
    }

    /** Provider /ai uses when none is chosen (null = first usable). */
    public function getDefault(People $company): ?string
    {
        $raw = $this->raw($company);
        $default = is_string($raw[self::DEFAULT_KEY] ?? null) ? $raw[self::DEFAULT_KEY] : null;

        return $default !== null && isset($this->defaultProviders()[$default]) ? $default : null;
    }

    /** @return array{default: string} */
    public function setDefault(People $company, string $key): array
    {
        $key = strtolower(trim($key));
        if (!$this->isUsable($this->load($company)[$key] ?? null)) {
            throw new \InvalidArgumentException('AI provider not connected for this company: ' . $key);
        }
        $row = $this->findRow($company);
        $raw = $this->raw($company);
        $raw[self::DEFAULT_KEY] = $key;
        $row->setConfigValue((string) json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->em->flush();

        return ['default' => $key];
    }

    /** @return list<array<string, mixed>> */
    public function listConfigured(People $company): array
    {
        $default = $this->resolve($company, null)[0] ?? null;
        $out = [];
        foreach ($this->load($company) as $key => $cfg) {
            if (!$this->isUsable($cfg)) {
                continue;
            }
            $out[] = [
                'provider' => $key,
                'label' => (string) ($cfg['label'] ?? $key),
                'model' => (string) ($cfg['model'] ?? ''),
                'enabled' => true,
                'default' => $key === $default,
                'has_key' => true,
                'api_key_masked' => $this->mask((string) $cfg['api_key']),
            ];
        }

        return $out;
    }

    /** @return array{company_id: int, config_id: int|null, config_key: string, providers: array<string, mixed>} */
    public function forUi(People $company): array
    {
        $masked = [];
        foreach ($this->load($company) as $key => $cfg) {
            $apiKey = (string) ($cfg['api_key'] ?? '');
            $masked[$key] = [
                'label' => $cfg['label'] ?? $key,
                'enabled' => (bool) ($cfg['enabled'] ?? false),
                'model' => (string) ($cfg['model'] ?? ''),
                'base_url' => (string) ($cfg['base_url'] ?? ''),
                'system_prompt' => (string) ($cfg['system_prompt'] ?? ''),
                'workspace_id' => (string) ($cfg['workspace_id'] ?? ''),
                'has_key' => $apiKey !== '',
                'api_key_masked' => $apiKey !== '' ? $this->mask($apiKey) : null,
            ];
        }

        return [
            'company_id' => (int) $company->getId(),
            'config_id' => $this->findRow($company)?->getId(),
            'config_key' => self::CONFIG_KEY,
            'default' => $this->getDefault($company),
            'providers' => $masked,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $providers
     * @return array{config_id: int}
     */
    public function save(People $company, array $providers): array
    {
        $existing = $this->load($company);
        $merged = [];
        foreach ($this->defaultProviders() as $key => $def) {
            $incoming = is_array($providers[$key] ?? null) ? $providers[$key] : [];
            $prev = $existing[$key] ?? [];
            $apiKey = trim((string) ($incoming['api_key'] ?? ''));
            if ($apiKey === '' || str_contains($apiKey, '*')) {
                $apiKey = (string) ($prev['api_key'] ?? '');
            }
            $merged[$key] = [
                'label' => (string) ($incoming['label'] ?? $def['label']),
                'enabled' => (bool) ($incoming['enabled'] ?? false),
                'model' => (string) ($incoming['model'] ?? $def['model']),
                'base_url' => (string) ($incoming['base_url'] ?? $def['base_url']),
                'api_key' => $apiKey,
                'system_prompt' => (string) ($incoming['system_prompt'] ?? $prev['system_prompt'] ?? ''),
                'workspace_id' => (string) ($incoming['workspace_id'] ?? $prev['workspace_id'] ?? ''),
            ];
        }

        $previousDefault = $this->getDefault($company);
        if ($previousDefault !== null) {
            $merged[self::DEFAULT_KEY] = $previousDefault;
        }
        $row = $this->findRow($company);
        if ($row === null) {
            $row = new Config();
            $row->setPeople($company);
            $row->setConfigKey(self::CONFIG_KEY);
            $row->setVisibility('private');
            $row->setModule($this->resolveModule());
            $this->em->persist($row);
        } elseif ($row->getModule() === null) {
            $row->setModule($this->resolveModule());
        }
        $row->setConfigValue((string) json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->em->flush();

        return ['config_id' => (int) $row->getId()];
    }

    /** @return array<string, mixed> */
    private function raw(People $company): array
    {
        $row = $this->findRow($company);
        $decoded = $row !== null ? json_decode((string) $row->getConfigValue(), true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string, array<string, mixed>> */
    private function load(People $company): array
    {
        $raw = $this->raw($company);
        $out = [];
        foreach ($this->defaultProviders() as $key => $def) {
            $cfg = array_merge($def, is_array($raw[$key] ?? null) ? $raw[$key] : []);
            $model = (string) ($cfg['model'] ?? '');
            $cfg['model'] = self::LEGACY_MODEL_ALIASES[$key][$model] ?? ($model !== '' ? $model : $def['model']);
            $out[$key] = $cfg;
        }

        return $out;
    }

    private function isUsable(mixed $cfg): bool
    {
        return is_array($cfg) && ($cfg['enabled'] ?? false) && !empty($cfg['api_key']);
    }

    private function findRow(People $company): ?Config
    {
        $row = $this->em->getRepository(Config::class)->findOneBy([
            'people' => $company,
            'configKey' => self::CONFIG_KEY,
        ]);

        return $row instanceof Config ? $row : null;
    }

    private function mask(string $key): string
    {
        $len = strlen($key);

        return $len <= 8
            ? str_repeat('*', $len)
            : substr($key, 0, 4) . str_repeat('*', max(4, $len - 8)) . substr($key, -4);
    }

    private function resolveModule(): Module
    {
        $repository = $this->em->getRepository(Module::class);
        foreach (['mcp', 'config', 'common', 'ui-manager', 'financial'] as $name) {
            $module = $repository->findOneBy(['name' => $name]);
            if ($module instanceof Module) {
                return $module;
            }
        }
        $module = $repository->findOneBy([], ['id' => 'ASC']);
        if ($module instanceof Module) {
            return $module;
        }
        throw new \RuntimeException('No module available to attach ai-providers config.');
    }
}

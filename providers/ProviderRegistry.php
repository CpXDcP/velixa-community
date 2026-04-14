<?php
declare(strict_types=1);

require_once __DIR__ . '/ProviderInterface.php';
require_once __DIR__ . '/GroqProvider.php';

class ProviderRegistry {
    /** @var array<string,ProviderInterface> */
    private static $map = null;

    /**
     * Enregistre un provider en sécurité (sans casser l’existant si un fichier n’est pas présent).
     */
    private static function safeRegister(callable $factory): void {
        try {
            $prov = $factory();
            if (!$prov instanceof ProviderInterface) return;
            $key = strtolower(trim($prov->getName()));
            if ($key === '') return;
            self::$map[$key] = $prov;
        } catch (\Throwable $e) {
            // On ignore proprement (pas de fatal) pour ne pas casser l’appli
        }
    }

    private static function boot(): void {
        if (self::$map !== null) return;
        self::$map = [];

        // ===== EXISTANT : IA génératives (NE PAS CASSER) =====
        $groq = new GroqProvider();
        self::$map[strtolower($groq->getName())] = $groq;

        // Ici, plus tard, tu ajouteras :
        // require_once __DIR__ . '/OpenAIProvider.php';
        // self::safeRegister(fn() => new OpenAIProvider());
        // require_once __DIR__ . '/AnthropicProvider.php';
        // self::safeRegister(fn() => new AnthropicProvider());
        // require_once __DIR__ . '/GeminiProvider.php';
        // self::safeRegister(fn() => new GeminiProvider());
        // require_once __DIR__ . '/AzureOpenAIProvider.php';
        // self::safeRegister(fn() => new AzureOpenAIProvider());

        // ===== NOUVEAUX : providers non-LLM (facultatifs) =====
        // On les charge *si présents*, sinon on ignore (zéro impact).
        $maybeProviders = [
            'EmailM365Provider.php'     => 'EmailM365Provider',
            'MailgunInboundProvider.php'=> 'MailgunInboundProvider',
            'WidgetClientProvider.php'  => 'WidgetClientProvider',
            // Ajoute ici d’autres providers facultatifs : SendGridProvider, SlackProvider, etc.
        ];

        foreach ($maybeProviders as $file => $class) {
            $path = __DIR__ . '/' . $file;
            if (is_file($path)) {
                require_once $path;
                self::safeRegister(function() use ($class) {
                    return new $class();
                });
            }
        }
    }

    /** @return ProviderInterface|null */
    public static function get(string $name) {
        self::boot();
        $key = strtolower(trim($name));
        return self::$map[$key] ?? null;
    }

    /** @return ProviderInterface[] */
    public static function all(): array {
        self::boot();
        return array_values(self::$map);
    }
}

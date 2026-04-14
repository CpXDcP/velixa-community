<?php
interface ProviderInterface {
    /**
     * Appel texte (et + tard multimodal) vers le modèle.
     * $opts : ['model'=>..., 'temperature'=>..., 'max_tokens'=>..., ...]
     */
    public function sendChat(string $prompt, array $opts = []): array;

    /** Nom lisible (pour UI/logs) */
    public function getDisplayName(): string;

    /** Slug unique: 'groq', 'openai', etc. */
    public function getName(): string;

    /** Retourne les options supportées par ce provider (pour l’UI admin) */
    public function supportedOptions(): array;

    /* ==============================
       Extensions multi-bots (facultatif)
       ============================== */

    /**
     * Envoi générique (mail/API) — par défaut renvoie not_supported.
     * @param array $payload  Ex: ['to'=>[], 'subject'=>..., 'text'=>...]
     */
    public function send(array $payload): array;

    /**
     * Vérification d’entrée (webhook/JWT/mTLS) — par défaut renvoie not_supported.
     * @param array $server   $_SERVER
     * @param string $rawBody Corps brut de la requête
     */
    public function verify(array $server, string $rawBody): array;
}


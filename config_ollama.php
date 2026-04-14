<?php
// --- Configuration Ollama pour Velixa ---

// URL de l’API Ollama locale
define("OLLAMA_URL", "http://127.0.0.1:11434/api/generate");

// Modèle pour l’audit de conformité
// (phi3:mini est léger et sous licence MIT, parfait pour commencer)
define("OLLAMA_MODEL", "phi3:mini");

// Paramètres de génération (déterminisme = auditabilité)
define("OLLAMA_TEMPERATURE", 0);
define("OLLAMA_SEED", 42);

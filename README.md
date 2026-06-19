# Démo IA Qualiscope

Application standalone pour l'extraction automatique d'une brochure de formation vers un formulaire RH pré-rempli.

## Modèles IA utilisés

| Usage | Modèle |
|---|---|
| Texte (PDF, DOCX, XLSX, TXT) | `gemma3:4b` |
| Vision (PDF scanné, images) | `qwen2.5vl:7b` |

---

## Option 1 — Docker (recommandé)

Tout est isolé : PHP, Apache et Ollama tournent dans des containers séparés.

```bash
# Lancer l'app (premier démarrage : télécharge les modèles ~10 GB)
docker compose up -d

# Suivre la progression du téléchargement des modèles
docker compose logs -f ollama-init

# Accéder à l'app
http://localhost:8082
```

### Commandes utiles

```bash
# Arrêter
docker compose down

# Redémarrer après modification du code
docker compose up -d --build

# Voir les logs de l'app
docker compose logs -f app
```

---

## Option 1 bis — Coolify

Déploie seulement l'application web depuis le [`Dockerfile`](./Dockerfile).
Ne réutilise pas le `docker-compose.yml` local tel quel, car il lance aussi Ollama.

Dans Coolify, ajoute ces variables d'environnement à l'application :

```env
OLLAMA_URL=http://100.65.44.72:11434
OLLAMA_TEXT_MODEL=gemma3:4b
OLLAMA_VISION_MODEL=qwen2.5vl:7b
OLLAMA_TIMEOUT=1800
```

L'Ollama local doit écouter sur le réseau et rester joignable depuis le conteneur Coolify.

---

## Option 2 — Lancement manuel (développement)

### 1. Démarrer Ollama

```bash
# Ollama sur la même machine
ollama serve

# Ollama sur une autre machine
OLLAMA_HOST=0.0.0.0 ollama serve
```

### 2. Vérifier les modèles disponibles

```bash
ollama list
# gemma3:4b et qwen2.5vl:7b doivent apparaître

# Installer si manquants
ollama pull gemma3:4b
ollama pull qwen2.5vl:7b
```

### 3. Lancer le serveur PHP

```bash
# Ollama sur la même machine
./run-demo.sh

# Ollama sur une autre machine
OLLAMA_URL=http://IP_MACHINE_IA:11434 ./run-demo.sh

# Changer les modèles sans modifier le code
OLLAMA_TEXT_MODEL=gemma3:4b OLLAMA_VISION_MODEL=qwen2.5vl:7b ./run-demo.sh
```

Puis ouvrir : `http://localhost:8082`

---

## Formats acceptés

- PDF texte natif
- PDF scanné (mode vision)
- DOCX
- XLSX
- TXT / CSV
- JPG / PNG / WEBP

---

## Pré-requis (option manuelle)

- PHP avec extensions `curl`, `zip`, `mbstring`
- `poppler-utils` (pour `pdftotext`)
- Ollama installé

```bash
composer install
```

---

## Déploiement réseau local

Voir `DEPLOY-LOCAL.md` pour le déploiement sur un serveur dédié avec Docker.

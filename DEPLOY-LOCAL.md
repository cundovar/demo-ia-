# Déploiement réseau local — demo-ia

## Architecture

```
Ton PC (navigateur)
        ↓  HTTP réseau local
Serveur local
  ├── Docker : Apache + PHP-FPM  (port 80)
  └── Ollama                     (port 11434)
```

---

## 1. Prérequis sur le serveur

```bash
# Docker
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker $USER
# (déconnecter/reconnecter)

# Ollama
curl -fsSL https://ollama.com/install.sh | sh

# Modèles IA
ollama pull gemma3:4b        # texte
ollama pull qwen2.5vl:7b     # vision (PDFs scannés)
```

---

## 2. Fichiers Docker à créer sur le serveur

Copie le projet dans `/opt/demo-ia/` puis crée ces deux fichiers :

### `Dockerfile`

```dockerfile
FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    poppler-utils \
    libzip-dev \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

RUN echo "upload_max_filesize = 100M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 110M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_execution_time = 0" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_input_time = 0" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit = 512M" >> /usr/local/etc/php/conf.d/uploads.ini

RUN mkdir -p /tmp/demo_ia_sessions && chmod 777 /tmp/demo_ia_sessions
```

### `docker-compose.yml`

```yaml
services:
  app:
    build: .
    ports:
      - "80:80"
    environment:
      - OLLAMA_URL=http://host-gateway:11434
      - OLLAMA_TEXT_MODEL=gemma3:4b
      - OLLAMA_VISION_MODEL=qwen2.5vl:7b
      - OLLAMA_TIMEOUT=1800
    extra_hosts:
      - "host-gateway:host-gateway"
    restart: unless-stopped
```

---

## 3. Lancer l'app

```bash
cd /opt/demo-ia

# Première fois
docker compose up -d --build

# Redémarrer après modification
docker compose restart

# Voir les logs
docker compose logs -f
```

---

## 4. Accès depuis ton PC

Trouve l'IP du serveur :
```bash
ip a | grep "inet " | grep -v 127
# exemple : 192.168.1.50
```

Puis ouvre dans le navigateur :
```
http://192.168.1.50
```

---

## 5. Ollama accessible depuis Docker

Ollama doit écouter sur toutes les interfaces (pas seulement localhost) :

```bash
# Sur le serveur, éditer le service ollama
sudo systemctl edit ollama
```

Ajouter :
```ini
[Service]
Environment="OLLAMA_HOST=0.0.0.0"
```

```bash
sudo systemctl daemon-reload
sudo systemctl restart ollama
```

---

## 6. Pourquoi le bouton "Annuler" fonctionne ici

Avec Apache + PHP-FPM (contrairement à `php -S`) :
- Chaque requête HTTP = un worker PHP séparé
- La requête `cancel` s'exécute **pendant** que `analyze` tourne
- `posix_kill()` peut tuer le processus Ollama bloquant

---

## 7. Mise à jour du code

```bash
cd /opt/demo-ia
git pull          # si dépôt git
docker compose up -d --build
```

---

## Résumé des ports

| Service | Port | Accessible depuis |
|---|---|---|
| App web | 80 | réseau local |
| Ollama | 11434 | interne Docker uniquement |

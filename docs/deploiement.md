# Déploiement

## Ce qui ne doit jamais être servi

| Chemin | Pourquoi |
|---|---|
| `var/` | **Les bases entières.** Une URL suffit à télécharger `narh.sqlite` : toutes les conversations, tout le corpus, tout le journal. Le bac à sable de l'agent y vit aussi. C'est le seul point vraiment sensible. |
| `src/`, `config/` | Du code inclus, jamais appelé directement. Aucun n'écrit de sortie, mais rien ne justifie de les exposer. |
| `tools/` | Le linter parcourt l'arborescence et affiche des chemins. Aucun intérêt en ligne. |
| `bootstrap.php`, `cli.php` | Idem. `cli.php` refusera de tourner hors ligne de commande (`PHP_SAPI`) : c'est une seconde barrière, pas la première. |
| `.git/`, `CLAUDE.md`, `README.md` | Fichiers de travail. |

## Laragon — nginx

Laragon sert par **nginx**, pas Apache : le `.htaccess` à la racine n'a aucun
effet. La règle doit être posée dans le vhost, sans quoi les bases sont publiques.

`D:\laragon\etc\nginx\sites-enabled\auto.narh-agent.test.conf` :

```nginx
server {
    listen 80;
    server_name narh-agent.test *.narh-agent.test;
    root "D:/laragon/www/narh-agent";
    index index.php;

    charset utf-8;

    # Les bases, le code, l'outillage. À poser avant tout le reste.
    location ~ ^/(var|src|config|tools)/ { return 404; }
    location ~ ^/(bootstrap|cli)\.php$   { return 404; }
    location ~ /\.                       { return 404; }
    location ~* \.(md|sqlite|sqlite-wal|sqlite-shm|lock)$ { return 404; }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass   127.0.0.1:9000;
        fastcgi_index  index.php;
        fastcgi_param  SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include        fastcgi_params;

        # Une réponse du modèle se lit en flux (SSE, à partir de P2) et peut
        # durer plusieurs minutes. La valeur par défaut couperait la trame au
        # milieu, sans erreur visible côté navigateur.
        fastcgi_read_timeout 300;
    }

    # Le flux d'une réponse ne doit pas être mis en tampon : sans cela, tout
    # arrive d'un bloc à la fin et le direct n'a plus d'intérêt.
    location = /api/chat.php {
        fastcgi_pass   127.0.0.1:9000;
        fastcgi_param  SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include        fastcgi_params;
        fastcgi_buffering off;
        fastcgi_read_timeout 300;
        gzip off;
    }

    # Les polices sont auto-hébergées : elles ne bougent jamais.
    location ~* \.(woff2|css|js|svg)$ {
        expires 30d;
        access_log off;
    }
}
```

Puis, dans Laragon : **Menu → Apache/Nginx → Reload**.

Les `.css` et `.js` étant mis en cache un mois, chaque écran leur ajoute une
version tirée de `filemtime` (`src/Ecran.php`). Sans elle, une correction
resterait invisible jusqu'à l'expiration.

## Vérifier

Après rechargement, ces cinq URL doivent toutes répondre **404** :

```bash
curl -s -o nul -w "%{http_code} var\n"    http://narh-agent.test/var/narh.sqlite
curl -s -o nul -w "%{http_code} src\n"    http://narh-agent.test/src/Ecran.php
curl -s -o nul -w "%{http_code} config\n" http://narh-agent.test/config/reglages.php
curl -s -o nul -w "%{http_code} tools\n"  http://narh-agent.test/tools/lint.php
curl -s -o nul -w "%{http_code} boot\n"   http://narh-agent.test/bootstrap.php
```

Et ces trois **200** :

```bash
curl -s -o nul -w "%{http_code} surface\n" http://narh-agent.test/
curl -s -o nul -w "%{http_code} etat\n"    "http://narh-agent.test/api.php?action=etat"
curl -s -o nul -w "%{http_code} fils\n"    http://narh-agent.test/api/fils.php
```

## Sans vhost

Le serveur intégré de PHP sert la racine du projet et suffit pour un poste de
travail. Il ne lit ni `.htaccess` ni la configuration nginx : **`var/` y est
accessible**. À réserver au développement, jamais sur un réseau partagé.

```bash
D:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe -S localhost:8100 -t D:\laragon\www\narh-agent
```

## Le moteur local

Ollama écoute sur `localhost:11434` — **ce port n'a pas à être exposé**, l'appel
part d'ici. Rien ne l'interroge avant P2.

# 12 — Revue de sécurité (lot 7, L7-03)

**Version :** 1.0 — 9 novembre 2026 · Référentiel : OWASP ASVS niveau 2 (ciblé)

## 1. Constat par domaine

| Domaine | Mesures en place | Référence |
|---|---|---|
| Authentification | Mots de passe Argon2id ; limitation des tentatives ; réinitialisation et invitation limitées (5 / 15 min) ; révocation des sessions (époque de session) | D-005, L1 |
| Sessions | Cookie chiffré, `HttpOnly`, `SameSite=Lax`, **`Secure` en production** (`.env.production.example`) ; expiration après 240 min d'inactivité | CDC §4.1 |
| CSRF | Sanctum (cookie XSRF-TOKEN + en-tête) sur toute écriture ; même origine SPA/API | D-043 |
| Autorisation | Permission par route (`can:`) ; champs sensibles masqués (`meta.redacted`) ; rapports, exports et recherche filtrés par permission | GEN-005, TEST-017 |
| Isolation | Portée globale « fail closed », clés étrangères composites `(company_id, id)`, test de non-fuite sur toutes les routes, réponse 404 avant validation | D-003, D-054, D-086 |
| Injection SQL | Constructeur de requêtes avec liaisons ; `whereRaw` uniquement avec paramètres liés | — |
| XSS | React échappe le contenu ; Blade `{{ }}` échappe les PDF ; CSP stricte (`script-src 'self'`) | Caddyfile |
| Injection CSV | Cellules commençant par `= + - @` neutralisées à l'export | D-123 |
| Fichiers | Stockage privé hors web, type détecté par contenu (XLSX vérifié comme archive), taille limitée, images réencodées (EXIF supprimé) | L0-13, L2-05 |
| Liens publics | Signés et expirants (30 jours) ; signature invalide ⇒ 404 ; limités à 30 requêtes/min | D-109 |
| Formules de coût | Moteur restreint sans exécution de code, testé au fuzzing | D-101 |
| Débit | Limitation générale de l'API (600 requêtes/min par utilisateur, configurable) | `API_RATE_LIMIT` |
| En-têtes | HSTS, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, CSP, `frame-ancestors 'none'`, en-tête `Server` retiré | Caddyfile |
| Erreurs | Problèmes RFC 9457 en français ; trace masquée quand `APP_DEBUG=false` (production) | — |
| Audit | Journal en ajout seul (déclencheurs), actions sensibles journalisées | L1-08 |
| Serveur | SSH par clé, root interdit, pare-feu 22/80/443, fail2ban, mises à jour de sécurité automatiques | `ops/provision-vps.sh` |

## 2. Dépendances (9 novembre 2026)

- `composer audit` : **aucune vulnérabilité**.
- `npm audit --omit=dev` (code livré au navigateur) : **aucune vulnérabilité**.
- `npm audit` (outils de développement) : 10 alertes (8 modérées, 2 critiques) sur `vitest` / `tinypool`, utilisés uniquement par les tests ; ils ne sont pas déployés. **Action :** passer à vitest 4 lors du prochain lot technique.

## 3. Reste à faire avant la production

1. Analyse dynamique OWASP ZAP (baseline) sur la préproduction, après déploiement HTTPS.
2. Note SSL Labs A sur le domaine définitif.
3. Revue manuelle des téléversements avec des fichiers malveillants réels (polyglottes PDF/ZIP).
4. TOTP pour le super-administrateur : proposé, non imposé (D-040).

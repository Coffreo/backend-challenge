# Test Technique - Extension du système de workers

## Contexte

Vous modifiez le projet existant.

Ce projet contient actuellement deux workers qui communiquent via RabbitMQ :
- **country-worker** : récupère la capitale d'un pays via l'API restcountries.com
- **capital-worker** : traite les messages contenant des capitales

[Accéder à la documentation du projet](./DOC.md)


## Objectif du test

Vous devez étendre ce système en ajoutant **deux nouveaux workers** et en créant **une API météo personnalisée**.

### Architecture finale attendue

```
input-worker → country-worker → capital-worker → weather-worker → output
```

## Consignes détaillées

### 1. Créer une API météo (api-weather)

**Technologies** : PHP (framework Symfony autorisé)

**Endpoints à implémenter** :

```http
GET /api/weather/{city}
GET /health
```

**Spécifications de l'API météo** :

- L'API doit retourner des données météo **aléatoires** pour les tests
- Format de réponse JSON :
```json
{
  "city": "Paris",
  "temperature": 15,
  "condition": "cloudy",
  "humidity": 65,
  "wind_speed": 12,
  "timestamp": "2024-01-15T10:30:00Z"
}
```

- **Conditions météo possibles** : `sunny`, `cloudy`, `rainy`, `snowy`, `stormy`
- **Température** : entre -10°C et 35°C
- **Humidité** : entre 20% et 90%
- **Vitesse du vent** : entre 0 et 50 km/h

### 2. Créer le worker d'entrée (input-worker)

**Rôle** : Point d'entrée du système avec routage intelligent

**Input** : Messages JSON avec une valeur simple :
```json
{
  "value": "France"
}
```
ou
```json
{
  "value": "Paris"
}
```

**Traitement** :
- Valider le format du message
- **Stratégie de routage** :
  1. Essayer d'abord comme nom de pays → publier sur queue `countries`
  2. Si le country-worker échoue → republier comme capitale sur queue `capitals`

**Queues de sortie** : `countries` puis `capitals` en cas d'échec

### 3. Créer le worker météo (weather-worker)

**Rôle** : Enrichir les données avec la météo

**Input** : Messages JSON avec :
```json
{
  "capital": "Paris",
  "country": "France"
}
```

**Traitement** :
- Appeler l'API météo avec le nom de la capitale
- Enrichir le message avec les données météo

**Queue d'entrée** : `capitals`
**Queue de sortie** : `weather_results`

**Format de sortie** :
```json
{
  "capital": "Paris",
  "country": "France",
  "weather": {
    "city": "Paris",
    "temperature": 15,
    "condition": "cloudy",
    "humidity": 65,
    "wind_speed": 12,
    "timestamp": "2024-01-15T10:30:00Z"
  }
}
```

### 4. Modifier le capital-worker existant

**Modification requise** :
- Au lieu de simplement logger le message, publier sur la queue `weather_results`
- Conserver le message original et ajouter un champ `processed: true`

### 5. Créer le worker de sortie (output-worker)

**Rôle** : Finaliser le traitement et afficher les résultats

**Input** : Messages enrichis avec la météo

**Traitement** :
- Logger le message final

## Structure de fichiers attendue

```
modules/
├── input-worker/
│   ├── src/
│   │   ├── index.php
│   │   └── MessageHandler.php
│   ├── Dockerfile
│   └── composer.json
├── weather-worker/
│   ├── src/
│   │   ├── index.php
│   │   └── MessageHandler.php
│   ├── Dockerfile
│   └── composer.json
├── output-worker/
│   ├── src/
│   │   ├── index.php
│   │   └── MessageHandler.php
│   ├── Dockerfile
│   └── composer.json
└── api-weather/
    ├── src/
    │   ├── Controllers/
    │   ├── Services/
    │   └── index.php
    ├── tests/
    ├── Dockerfile
    └── composer.json
```

## Configuration Docker

Ajouter les nouveaux services dans `docker-compose.dev.yml` pour :
- input-worker
- weather-worker
- output-worker
- api-weather (port 8080)


## Commandes utiles

```sh
# Démarrer l'environnement de développement
docker compose -f docker-compose.dev.yml up -d --build

# Arrêter l'environnement de développement
docker compose -f docker-compose.dev.yml down

# Voir les logs en temps réel
docker compose -f docker-compose.dev.yml logs -f


```

### Interfaces de monitoring

- **Dozzle (logs)** : http://localhost:9999/
- **RabbitMQ Management** : http://localhost:15672/ (guest/guest)
- **API Météo** : http://localhost:8080/

## Environnement de développement

### Fonctionnalités

L'environnement de développement offre plusieurs avantages :

- **Hot Reload automatique** : Les workers redémarrent automatiquement via `watchexec` lors de modifications des fichiers PHP dans `src/`
- **Volumes partagés** : Les modifications sur l'host sont immédiatement propagées dans les containers
- **Vendor persistant** : Les dépendances composer installées dans le container sont accessibles depuis l'host
- **Devcontainer** : Un environnement dockerisé prêt à l'emploi pour développer confortablement

### Mise à jour des packages partagés

Lorsque vous modifiez un package dans le dossier `packages/` (comme `rabbitmq`), utilisez le script fourni pour mettre à jour tous les projets :

```sh
# Mettre à jour le package internals/rabbitmq sur tous les projets
./scripts/update-rabbitmq-package.sh
```

## Tests

### Test du pipeline complet

1. Démarrer tous les services : `docker compose -f docker-compose.dev.yml up -d`
2. Envoyer un message de test : `./scripts/test-e2e.sh France` ou `./scripts/test-e2e.sh Madrid`
3. Suivre les logs du pipeline avec le filtre `challenge-pipeline` dans **Dozzle** (http://localhost:9999/) pour tracer les étapes principales du pipeline
4. Vérifier que le message final contient les données météo dans **output-worker**

**Alternative** : Envoyer manuellement via l'interface **RabbitMQ Management** (http://localhost:15672/) :
- Exchange : `amq.default`
- Routing key : `input`
- Payload :
```json
{
  "value": "France"
}
```

### Tests unitaires

```bash
# Lancer les tests unitaires par module
docker compose -f docker-compose.dev.yml exec country-worker composer test
docker compose -f docker-compose.dev.yml exec input-worker composer test
docker compose -f docker-compose.dev.yml exec weather-worker composer test
docker compose -f docker-compose.dev.yml exec output-worker composer test
docker compose -f docker-compose.dev.yml exec api-weather composer test
```

### Test de l'API météo

```bash
# Test direct de l'API
curl http://localhost:8080/api/weather/Paris
curl http://localhost:8080/health

# Test E2E du pipeline complet
./scripts/test-e2e.sh Italy
./scripts/test-e2e.sh Madrid
```

### Suivi des logs

Pour suivre efficacement le pipeline dans **Dozzle** :
- Accéder à http://localhost:9999/
- Utiliser le filtre `challenge-pipeline` pour tracer les étapes principales du pipeline
- Observer la progression : input-worker → country-worker → capital-worker → weather-worker → output-worker

## Critères d'évaluation

### Fonctionnalités (40%)
- [ ] API météo fonctionnelle avec endpoints demandés
- [ ] Workers implémentés selon les spécifications
- [ ] Pipeline complet fonctionnel
- [ ] Gestion d'erreurs basique

### Code Quality (30%)
- [ ] Code propre et bien structuré
- [ ] Respect des conventions PHP
- [ ] Documentation des fonctions importantes
- [ ] Séparation des responsabilités

### Architecture (20%)
- [ ] Réutilisation du package RabbitMQ existant
- [ ] Configuration Docker cohérente
- [ ] Gestion des variables d'environnement
- [ ] Structure de fichiers logique

### Tests et robustesse (10%)
- [ ] Tests unitaires pour l'API météo
- [ ] Gestion des cas d'erreur (API indisponible, message malformé)
- [ ] Logs informatifs
- [ ] Health checks

### 🎁 Bonus (optionnel)
- [ ] **Proxy météo** : Créer un endpoint proxy dans l'API météo qui appelle une vraie API météo externe (ex: OpenWeatherMap) et mappe le retour vers le format attendu.

## Livrables attendus

1. **Code source** : Tous les fichiers modifiés et nouveaux
2. **Documentation** : README mis à jour avec les nouvelles instructions
3. **Tests** : Au minimum des tests pour l'API météo
4. **Démonstration** : Pipeline fonctionnel avec un exemple de message

---

**Bon courage !** 🚀

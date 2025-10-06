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
input-worker → country-worker → capital-worker → weather-worker → output-worker
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

```bash
# Démarrer l'environnement
make up

# Envoyer un message de test
make test-message

# Arrêter l'environnement
make down
```

## Tests

### Test du pipeline complet

1. Démarrer tous les services : `make up`
2. Envoyer un message de test : `make test-message`
3. Vérifier dans les logs que le message traverse tous les workers
4. Vérifier que le message final contient les données météo

**Alternative** : Envoyer manuellement via l'interface RabbitMQ (Exchange `amq.default`, routing key `input`) :
```json
{
  "value": "France"
}
```

### Test de l'API météo

```bash
# Test direct de l'API
curl http://localhost:8080/api/weather/Paris
curl http://localhost:8080/health
```

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

## Livrables attendus

1. **Code source** : Tous les fichiers modifiés et nouveaux
2. **Documentation** : README mis à jour avec les nouvelles instructions
3. **Tests** : Au minimum des tests pour l'API météo
4. **Démonstration** : Pipeline fonctionnel avec un exemple de message

---

**Bon courage !** 🚀

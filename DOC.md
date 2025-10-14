# Projet

Ce projet propose deux *workers* qui communiquent de manière fiable, asynchrone et tolérante aux pannes à travers une couche événementielle. Le projet est structuré en simili-microservice, et le code proposé sous la forme d'un simili-monorepo.

## Démarrage

Ce projet fournit un environnement dockerisé prêt à l'emploi. Équipez-vous donc de [Docker](https://www.docker.com/products/docker-desktop/), qui vous permettra de garder un environnement propre. Installez également [Make](https://www.gnu.org/software/make/#download).

À la racine du projet, créez un fichier `.env.dev`, et copiez-collez le contenu suivant (le même bazar que `.env.dev.example`) :

```properties
RABBITMQ_HOST=localhost
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest

RABBITMQ_QUEUE_CAPITALS=capitals
RABBITMQ_QUEUE_COUNTRIES=countries
```

Cela fait, démarrez l'application en local :

```sh
make up
```

ou bien, si vous n'avez pas `Make` :

```sh
docker-compose up -d --build
```

*That's all, folks!*

Stoppez le conteneur avec `make down`, ou bien utilisez la commande suivante :

```sh
docker-compose down
```

En plus des deux *workers*, deux modules ont été apportés pour permettre un *monitoring* et un *test* plus efficaces, et confortables :

- [Dozzle](https://dozzle.dev/) sur <http://localhost:9999/> offre un panneau de contrôle des différents conteneurs (et permet d'accéder aux logs).
- L'interface d'administration de RabbitMQ sur <http://localhost:15672/>, dont vous pouvez utiliser la console pour écrire un message. Les *routine key* prises en charge sont `countries` et `capitals`.

L'interface RabbitMQ permet notamment de placer des messages sur une queue. Dans la section `Exchange`, sélectionnez `amq.default`. Un formulaire apparaît. Par exemple, avec la *routine key* `countries`, utilisez la valeur suivante :

```json
{
  "country_name": "France"
}
```

Utilisez Dozzle pour regarder les différents logs évoluer dans chaque conteneur.

Vous pouvez arrêter les conteneurs à l'envi : les *workers* essaieront de se reconnecter, et gèrent un certain nombre d'erreurs, en plus d'essayer de se reconnecter seuls. Le code supporte plusieurs types de dysfonctionnements liés à la connexion avec RabbitMQ. Essayez également de mettre n'importe quelle valeur.

## Développement

Par commodité, un `devcontainer` est fourni avec le projet, permettant de développer dans un environnement dockerisé, prêt à l'emploi et équipé du matériel nécessaire pour coder confortablement.

## Environnement optimisé pour le Dev

Pour un développement plus efficace, utilisez l'environnement de développement optimisé :

```sh
docker-compose -f docker-compose.dev.yml up -d --build
```

### Fonctionnalités

L'environnement de développement offre plusieurs avantages :

- **Hot Reload automatique** : Les workers redémarrent automatiquement via `watchexec` lors de modifications des fichiers PHP dans `src/`
- **Volumes partagés** : Les modifications sur l'host sont immédiatement propagées dans les containers
- **Vendor persistant** : Les dépendances composer installées dans le container sont accessibles depuis l'host

### Mise à jour des packages partagés

Lorsque vous modifiez un package dans le dossier `packages/` (comme `rabbitmq`), utilisez le script fourni pour mettre à jour tous les projets :

```sh
# Mettre à jour le package internals/rabbitmq sur tous les projets
./update-rabbitmq-package.sh
```

### Commandes utiles

```sh
# Démarrer l'environnement de développement
docker compose -f docker-compose.dev.yml up -d --build

# Arrêter l'environnement de développement
docker compose -f docker-compose.dev.yml down

# Voir les logs en temps réel
docker compose -f docker-compose.dev.yml logs -f

# Lancer les tests
docker compose -f docker-compose.dev.yml exec country-worker php vendor/bin/phpunit
docker compose -f docker-compose.dev.yml exec capital-worker php vendor/bin/phpunit
docker compose -f docker-compose.dev.yml exec input-worker php vendor/bin/phpunit

# Lancer un test E2E
curl -u guest:guest -H "Content-Type: application/json" -X POST \
  -d '{"properties":{},"routing_key":"input","payload":"{\"value\":\"France\"}","payload_encoding":"string"}' \
  http://localhost:15672/api/exchanges/%2F/amq.default/publish

```

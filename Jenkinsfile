pipeline {
    agent any

    options {
        timestamps()
        disableConcurrentBuilds()
    }

    stages {
        stage('Checkout') {
            steps { checkout scm }
        }

        stage('Composer validation') {
            steps { sh 'composer validate --no-check-publish' }
        }

        stage('Static checks') {
            steps {
                sh 'find app tests -type f -name "*.php" -print0 | xargs -0 -n1 php -l'
                sh 'node tests/check-inline-js.js'
                sh '''
                    APP_ENV=local APP_NAME="Inventory Manager" APP_URL=http://localhost:8090 APP_PORT=8090 \
                    SESSION_IDLE_TIMEOUT=1800 APP_IMAGE=inventory-manager IMAGE_TAG=ci \
                    DB_HOST=db DB_PORT=3306 DB_NAME=inventory_manager DB_USER=inventory_app \
                    DB_PASSWORD=compose-check-only MYSQL_ROOT_PASSWORD=compose-check-only \
                    docker compose config -q
                '''
            }
        }

        stage('Tests') {
            steps { sh 'php tests/run.php' }
        }

        stage('Build tagged app image') {
            steps {
                sh '''
                    APP_ENV=local APP_NAME="Inventory Manager" APP_URL=http://localhost:8090 APP_PORT=8090 \
                    SESSION_IDLE_TIMEOUT=1800 APP_IMAGE=inventory-manager IMAGE_TAG="ci-${BUILD_NUMBER}" \
                    DB_HOST=db DB_PORT=3306 DB_NAME=inventory_manager DB_USER=inventory_app \
                    DB_PASSWORD=compose-check-only MYSQL_ROOT_PASSWORD=compose-check-only \
                    docker compose build app
                '''
            }
        }
    }
}

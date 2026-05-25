# frozen_string_literal: true

task :up do
  sh 'docker compose up -d'
end

task :serve do
  sh 'docker compose up --build'
end

task :down do
  sh 'docker compose down'
end

task :install do
  sh 'docker compose exec app composer install'
  sh 'docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction'
end

task :migrate do
  sh 'docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction'
end

task :test do
  sh 'docker compose exec app php bin/phpunit'
end

task :worker do
  sh 'docker compose exec app php bin/console messenger:consume async -vv'
end

task :bash do
  sh 'docker compose exec app sh'
end

task :logs do
  sh 'docker compose logs -f'
end

task :cmd, [:command] do |_task, args|
  command = args[:command]
  abort 'Usage: rake "cmd[php bin/console debug:router]"' if command.nil? || command.strip.empty?

  sh "docker compose exec app #{command}"
end

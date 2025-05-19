## Como usar

1. **Configure as variáveis de ambiente**  
   Copie o arquivo `.env.example` para `.env` e preencha as variáveis necessárias:
   ```
   cp .env.example .env
   # Edite o arquivo .env e preencha as chaves de API
   ```

2. **Instale as dependências PHP usando Docker**  
   ```
   docker run -it --rm -v $(pwd):/app -w /app composer install
   ```

3. **Suba o serviço do banco de dados pgvector**  
   ```
   docker compose up -d pgvector
   ```

4. **Indexe as conversas do repositório desejado**  
   Substitua `user/repo` pelo repositório desejado:
   ```
   docker compose run -it --rm app php application index-vector user/repo
   ```

5. **Faça perguntas ao chat**  
   Por exemplo, para listar 10 conversas sobre endpoints HTTP:
   ```
   docker compose run -it --rm app php application chat "List 10 conversations about http endpoints"
   ```

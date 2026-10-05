# Operação do Web Chat via SSE

O runtime é PHP CLI + Workerman, em um único processo fora do PHP-FPM. Nginx distribui `/chat/realtime` sem buffering/cache. EventSource reconecta automaticamente e envia Last-Event-ID; o broker mantém até 256 eventos/1 MiB de replay e o cliente recupera o histórico durável se houver lacuna. Cada token expira e só autoriza uma sessão e um papel. O broker não consulta banco nem chama o Mautic.

## Instalação e migração de WebSocket

1. Compile os assets localmente (`npm ci && npm test`). Instale `composer install --working-dir=Realtime --no-dev --prefer-dist`.
2. Defina o mesmo segredo no Mautic e em `/home/forge/.config/mautic-webchat-sse.env` (modo 0600):

```dotenv
MAUTIC_WEBCHAT_REALTIME_SECRET=segredo-de-pelo-menos-32-caracteres
WEBCHAT_HOST=127.0.0.1
WEBCHAT_PORT=8790
```

No Mautic, use `MAUTIC_WEBCHAT_REALTIME_URL=https://mautic.exemplo.com/chat/realtime` e `MAUTIC_WEBCHAT_REALTIME_INTERNAL_URL=http://127.0.0.1:8790`. URLs `wss://` antigas são convertidas para HTTPS durante a emissão do token.

3. Copie `Realtime/mautic-webchat-sse.service` para `/etc/systemd/system/`, ajustando host, usuário e PHP. Execute `systemctl daemon-reload && systemctl enable --now mautic-webchat-sse`. A unidade limita CPU, memória, buffers e descritores. Pare/remova somente `mautic-webchat-realtime` do PM2 e salve a lista; o PM2 de outros sites é independente.
4. Configure o proxy de [`nginx.conf.example`](nginx.conf.example), execute `nginx -t` e recarregue Nginx. Retire os headers de upgrade WebSocket e invalide o cache de HTML/loader/assets antigos.
5. Para resposta da IA rápida sem ocupar FPM, instale `mautic-inbox-ai.service` + `.timer`. O timer executa uma instância por vez, três segundos após a anterior terminar. O worker mantém os locks existentes. Retire apenas o cron duplicado `mautic:inbox:ai:work`; o cron tradicional continua sendo uma alternativa com latência de até um minuto.

## Verificação

```bash
systemctl status mautic-webchat-sse
curl -fsS http://127.0.0.1:8790/health
journalctl -u mautic-webchat-sse -n 30
systemctl status mautic-inbox-ai.timer
```

O health deve informar `runtime=php`, `transport=sse`, conexões e bytes de replay. Nenhum fluxo deve chamar `/chat/api/realtime/ingest`; esse endpoint antigo foi removido. POST `/chat/api/realtime/events` autentica o token HMAC, resolve a identidade no servidor e limita eventos por sessão/papel. O navegador serializa ações, confirma leitura uma vez por ID e limita digitação a um evento a cada dois segundos. O indicador humano expira em seis segundos; o evento do worker de IA pode durar até 120 segundos, com parada explícita ao concluir.

Mensagens são persistidas antes de publicar. O ACK HTTP reconcilia o envio se a conexão SSE estiver indisponível. Som, contador de não lidas, API JavaScript, identificação e ajuste de teclado móvel permanecem ativos.

## Banco de dados

Nunca execute testes de kernel, fixtures ou reset contra a instalação de produção. Faça backup novo, verifique gzip e SHA256 antes de qualquer alteração estrutural. Consulte o schema e EXPLAIN antes de acrescentar índices. A versão 1.2 utiliza:

```sql
CREATE INDEX webchat_message_direction ON webchat_messages (session_id, direction, id);
CREATE INDEX inbox_message_latest ON meta_messages (conversation_id, date_added, id);
CREATE INDEX inbox_message_inbound ON meta_messages (conversation_id, direction, date_added, id);
```

Aplique somente índices ausentes, com `ALGORITHM=INPLACE, LOCK=NONE` quando suportado; adapte o prefixo real. Não execute atualização geral de schema. A metadata de WebChat registra o primeiro, e o Inbox registra os dois últimos pelo listener `ChatQueryIndexes`.

O histórico usa uma projeção escalar de no máximo 100 mensagens. Marcadores de leitura usam `GREATEST` para preservar o avanço entre abas concorrentes. Leituras repetidas retornam sem escrita/publicação; uma leitura nova atualiza WebChat, Meta e envios humanos em três statements dentro de uma transação, sem consultas por mensagem. A lista do Inbox busca últimas mensagens e identidades em dois SELECTs por página. O WebChat busca sessões/widgets/contatos e previews em mais dois SELECTs por página, pelo contrato opcional `BatchChannelTransportInterface`. Reconectar não salva novamente um contato cuja identidade não mudou.

## Diagnóstico e rollback

- Sem tempo real: confira serviço, segredo, URL HTTPS, token válido e buffering do proxy.
- Digitação presa: indicadores expiram em seis segundos mesmo sem evento de parada.
- Mensagem duplicada: confira o `client_id`; o banco conserva a restrição de unicidade.
- CPU alta: compare a taxa de POSTs, FPM ativo e health do broker. Recibos de leitura não podem se repetir para o mesmo ID.
- Rollback: pare SSE/timer, restaure código/configuração previamente salvos e recarregue serviços. Preserve o histórico e os índices; não remova tabelas como parte do rollback.

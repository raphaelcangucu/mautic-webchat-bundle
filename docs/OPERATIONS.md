# Operação do Mautic Realtime Web Chat

## Verificações de saúde

```bash
curl -fsS http://127.0.0.1:8790/health
curl -I 'https://mautic.exemplo.com/chat/embed.js?id=pub_EXEMPLO'
pm2 status mautic-webchat-realtime
nginx -t
```

O gateway deve responder `ok`, o loader deve retornar `200` e uma segunda leitura deve mostrar `X-WebChat-Cache: HIT`. O processo deve permanecer `online` após um reload do Nginx/PHP-FPM.

## Nginx

O exemplo em [`nginx.conf.example`](nginx.conf.example) separa três funções:

1. `/chat/realtime` faz upgrade WebSocket e não usa cache;
2. `/chat/embed.js`, `/chat/generate.js`, `/chat/widget/*` e `/chat/demo/*` podem usar cache FastCGI curto;
3. `/chat/api/*` permanece dinâmico e sem cache.

Não aplique cache genérico em `/chat/api/`: sessão, mensagens e leitura são específicas do visitante.

## Supervisão do gateway

Use o `Realtime/ecosystem.config.cjs` com PM2 ou traduza as mesmas variáveis para systemd. Após alterar segredo, URL ou porta, reinicie com `--update-env` e valide o endpoint local antes de testar no navegador.

## Diagnóstico

| Sintoma | Verificação |
| --- | --- |
| Widget não abre | Domínio permitido, CSP, resposta de `/chat/embed.js` e console do navegador. |
| Estado “reconectando” | Processo PM2, proxy WebSocket, segredo comum e URL `wss://`. |
| Mensagem só aparece após recarregar | Endpoint de ingestão, publicação do gateway e token da sala. |
| IA não responde | Sessão atribuída, agente habilitado para Web Chat, documentos publicados e worker do Inbox. |
| IA responde sem nome no indicador | Versões mínimas Inbox 1.3 e Web Chat 1.0 e bundles recompilados. |
| Recibos não avançam | Janela visível, evento de leitura e histórico HTTP reconciliado. |

## Backup e atualização

Antes de atualizar em produção:

1. registre as versões atuais dos três plugins;
2. faça backup do banco e do diretório `plugins`;
3. valide dependências Composer sem executar testes de banco;
4. publique os arquivos, compile quando necessário e recarregue os plugins;
5. reinicie o gateway e o PHP-FPM;
6. valide um widget em domínio autorizado e uma conversa de teste;
7. confirme digitação, entrega, leitura, resposta humana, resposta da IA e resolução.

## Reversão

Desative o widget para bloquear novas sessões, pare o gateway e restaure a versão anterior do plugin. As sessões e mensagens permanecem no banco para auditoria. A remoção de tabelas exige uma decisão operacional separada e nunca faz parte do rollback normal.

# Arquitetura do Mautic Realtime Web Chat

## Componentes

| Componente | Responsabilidade |
| --- | --- |
| Loader `/chat/embed.js` | Descobre o widget, valida a origem e injeta o `iframe` sem conflitar com CSS ou JavaScript do site. |
| Widget Svelte | Renderiza identificação, histórico, composer, presença, digitação e recibos. Mantém uma sessão retomável no navegador. |
| API pública PHP | Cria sessões, persiste mensagens, entrega histórico e recebe o fallback HTTP. |
| Gateway Node.js | Autentica WebSockets, organiza salas por sessão, retransmite eventos e envia ações do visitante ao endpoint de ingestão. |
| `MauticWebChatBundle` | Mantém widgets, sessões e mensagens e implementa o transporte do canal Web Chat. |
| `MauticInboxBundle` | Mantém estado do atendimento, atendentes, notas, rascunhos, contatos e sessões de IA. |
| Pi/Codex | Executa somente agentes configurados e devolve as respostas pelo transporte do canal. |

## Persistência

- `webchat_widgets`: aparência, domínios, conta do Inbox, agente inicial e estado publicado;
- `webchat_sessions`: visitante, contato vinculado, conversa do Inbox, página de origem, UTMs e presença;
- `webchat_messages`: timeline durável, autoria, identificadores idempotentes e estados de entrega/leitura;
- `inbox_conversation_states`: fila, responsável humano, sessão da IA, limite, status e tomada humana;
- tabelas do conector Meta: envelope de conversa e mensagem já compreendido pelo Inbox.

## Protocolo em tempo real

O PHP assina tokens HMAC curtos com `session public id`, papel e expiração. O navegador usa papel `visitor`; o frontend administrativo recebe um token próprio. O gateway nunca consulta o banco diretamente.

Eventos principais:

- `message.created`: mensagem persistida e disponível na timeline;
- `message.delivered`: o destinatário recebeu o evento;
- `message.read`: a interface exibiu o item;
- `typing.started` e `typing.stopped`: estado efêmero com papel e nome do autor;
- `presence.changed`: conexão ou desconexão da sessão.

O widget confirma entrega ao receber a mensagem e leitura quando a janela está aberta e o item entra no histórico visível. O Inbox segue a mesma regra. Eventos efêmeros não alteram a timeline.

## Consistência e recuperação

1. Uma mensagem ganha `client_id` antes do envio.
2. A API grava a mensagem e aplica a restrição de unicidade.
3. O servidor publica o evento após a persistência.
4. O cliente reconcilia a mensagem otimista com o identificador durável.
5. Ao reconectar, o cliente solicita o histórico HTTP e elimina duplicatas.

Se o WebSocket falhar, o envio e o histórico continuam por HTTP. A interface indica reconexão sem apagar o editor ou o histórico local.

## Fronteiras de segurança

- o site hospedeiro nunca recebe credenciais do Mautic;
- o `iframe` limita a superfície de CSS e DOM;
- a origem precisa constar no widget e na CSP;
- o gateway aceita somente tokens válidos e payloads limitados;
- o endpoint de ingestão exige o segredo interno;
- APIs administrativas continuam protegidas pelas ACLs e pelo CSRF do Mautic;
- notas internas permanecem exclusivas do Inbox.

## Integração de IA

O Web Chat não chama o modelo diretamente. O Inbox seleciona o agente e monta seu contexto publicado, executa o Pi/Codex e chama o transporte para responder. Essa separação mantém as regras de canal, o contador de mensagens, a tomada humana e o encerramento num único lugar.

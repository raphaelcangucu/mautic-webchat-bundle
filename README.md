# Mautic Realtime Web Chat

Canal de chat incorporável para o [Mautic Omnichannel Inbox](https://github.com/raphaelcangucu/mautic-inbox-bundle). O visitante conversa no site em tempo real e a equipe recebe a conversa no mesmo Inbox usado por WhatsApp, Instagram e Facebook. O canal preserva atribuição humana, notas internas, respostas prontas, estágio do contato e agentes de IA executados pelo Pi/Codex.

![Configuração do Web Chat no Mautic](docs/screenshots/webchat-admin.jpg)

## O que entrega

- widget responsivo e isolado em `iframe`, instalado por uma única tag `script`;
- sessão retomável, histórico durável e identificação por nome, e-mail e telefone, com obrigatoriedade configurável por widget;
- SSE autenticado com presença e indicadores de digitação dos dois lados;
- confirmações de entrega e leitura para visitante e atendente;
- entrada no Inbox com canal, página de origem, referência e UTMs;
- atendimento humano, transferência, notas, encerramento e respostas prontas;
- atribuição automática opcional a um agente de IA configurado no Inbox;
- agente identificado pelo nome durante digitação e respostas;
- criação ou vínculo do contato Mautic para uso do estágio do funil;
- configuração visual, lista de domínios permitidos, conta de apoio e página de demonstração;
- recuperação por HTTP quando o SSE estiver momentaneamente indisponível;
- heartbeat e sincronização do histórico quando a aba volta ao primeiro plano;
- contador de mensagens não lidas e aviso sonoro para novas respostas;
- adaptação à área visível do teclado virtual em dispositivos móveis.

## Identidade por widget

Em **Web Chat**, cada widget tem nome, cor de destaque, saudação e conta do Inbox próprios. A inicial, a identificação da equipe e o rodapé acompanham o nome configurado, inclusive na prévia do editor. Por exemplo, **Chat Codificar** usa a inicial **C** e pode ter a cor verde `#168354` sem alterar o widget da Macro Markets.

O formulário inicial pede **nome, e-mail e telefone**. As opções **Pedir nome**, **Pedir e-mail** e **Pedir telefone** tornam cada campo obrigatório para novas conversas. O telefone aceita DDD no país configurado na conta ou um número internacional com `+`, é validado no servidor e salvo em E.164 no campo **Mobile** do contato Mautic e na sessão do chat. Informar telefone não registra consentimento para WhatsApp. Sessões já autenticadas continuam podendo retomar o histórico após alterações de obrigatoriedade.

![Formulário inicial com nome, e-mail e telefone obrigatórios](docs/screenshots/widget-contact-form.png)

Para testar em uma landing page editável, crie uma página no **Mautic Pages** e inclua o código de instalação do widget antes de `</body>`. O botão da página também pode abrir o chat com `window.MauticWebChat.open()`.

![Widget verde da Codificar em uma página do Mautic, com resposta em tempo real e confirmação de leitura](docs/screenshots/codificar-widget.png)

## Fluxo

1. A página carrega `/chat/embed.js?id=pub_...`.
2. O loader cria um `iframe` isolado, validado contra a lista de origens do widget.
3. O visitante inicia ou retoma uma sessão. Nome e e-mail podem vincular um contato do Mautic.
4. Cada mensagem é persistida antes de ser publicada no SSE.
5. O `MauticWebChatBundle` entrega o evento ao `MauticInboxBundle` pelo contrato `ChannelTransportInterface`.
6. Atendente e visitante recebem mensagens, digitação, entrega e leitura em tempo real.
7. Se configurado, o Inbox transfere a conversa ao agente Pi/Codex, que usa o mesmo transporte para responder.
8. O humano ou o agente resolve a conversa; uma nova mensagem do visitante pode reabri-la.

```mermaid
flowchart LR
    Site[Site com embed.js] --> Frame[Widget Svelte em iframe]
    Gateway[Broker PHP CLI + Workerman] -->|SSE: mensagens, digitação e leitura| Frame
    Frame -->|Sessão, histórico e fallback HTTP| WebChat[MauticWebChatBundle]
    Frame -->|POST autenticado| WebChat
    WebChat -->|Publicação HTTP local| Gateway
    WebChat --> Store[(webchat_widgets\nwebchat_sessions\nwebchat_messages)]
    WebChat --> Inbox[MauticInboxBundle]
    Inbox --> Operator[Inbox Svelte do atendente]
    Gateway -->|SSE| Operator
    Operator -->|POST: digitação e leitura| WebChat
    Inbox --> AI[Agente Pi/Codex opcional]
    AI --> WebChat
    Inbox --> Contact[Contato e estágio do funil]
```

O Web Chat mantém widgets, sessões e mensagens próprias. Para conservar compatibilidade com o atendimento já existente, ele usa a conversa durável do conector Meta como envelope do Inbox. A extensão `ChannelTransportInterface`, disponível desde o Inbox 1.3, delega envio, leitura, digitação e metadados ao canal Web Chat sem mudar o comportamento dos canais Meta.

Veja a arquitetura detalhada em [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) e o guia de operação em [docs/OPERATIONS.md](docs/OPERATIONS.md).

## Dependências

- PHP 8.2 ou superior;
- Mautic 7;
- `raphaelcangucu/mautic-meta-bundle` 0.14 ou superior;
- `raphaelcangucu/mautic-inbox-bundle` 1.4 ou superior;
- PHP CLI com pcntl/posix e Workerman 5.2;
- Nginx ou outro proxy reverso com buffering desativado para SSE;
- systemd para supervisionar o broker PHP e o worker de IA.

O frontend usa Svelte 5 e TypeScript. Os assets são compilados localmente e distribuídos em `Assets/dist`. O tempo real roda em PHP; Node.js é necessário apenas para compilar e para o runtime opcional de IA Pi.

## Instalação

Instale o bundle em `plugins/MauticWebChatBundle`, instale as dependências do gateway e compile os frontends:

```bash
composer require raphaelcangucu/mautic-webchat-bundle
cd plugins/MauticWebChatBundle
npm ci
npm run build
composer install --working-dir=Realtime --no-dev
php ../../bin/console mautic:plugins:reload --env=prod
```

O reload registra `webchat_widgets`, `webchat_sessions` e `webchat_messages`. Em produção, faça backup e confira o banco selecionado antes de registrar ou atualizar o plugin.

Defina variáveis exclusivas do Web Chat. O segredo deve conter pelo menos 32 caracteres e ser idêntico no PHP e no broker PHP:

```dotenv
MAUTIC_WEBCHAT_REALTIME_SECRET=troque-por-um-segredo-aleatorio-longo
MAUTIC_WEBCHAT_REALTIME_URL=https://mautic.exemplo.com/chat/realtime
MAUTIC_WEBCHAT_REALTIME_INTERNAL_URL=http://127.0.0.1:8790
WEBCHAT_HOST=127.0.0.1
WEBCHAT_PORT=8790
```

Instale a unidade [`Realtime/mautic-webchat-sse.service`](Realtime/mautic-webchat-sse.service) e configure o EnvironmentFile privado descrito no guia de operação:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now mautic-webchat-sse
curl -fsS http://127.0.0.1:8790/health
```

Adicione o proxy e o cache público descritos em [`docs/nginx.conf.example`](docs/nginx.conf.example), execute `nginx -t` e recarregue o Nginx.

Abra **Web Chat** no menu do Mautic, crie um widget, selecione a conta usada pelo Inbox, autorize os domínios e copie a tag gerada:

```html
<script async src="https://mautic.exemplo.com/chat/embed.js?id=pub_EXEMPLO"></script>
```

`/chat/generate.js` permanece como alias de compatibilidade. Use `/chat/embed.js` em novas instalações para evitar bloqueadores que classificam nomes genéricos como scripts de anúncios.

### API JavaScript para aplicações reativas

O loader publica `window.MauticWebChat` e mantém comandos em fila enquanto o `iframe` inicia. Isso permite integrar botões e usuários autenticados de React, Vue, Svelte ou JavaScript simples sem acessar o conteúdo interno do frame:

```js
window.MauticWebChat?.identify({
  name: usuario.nome,
  email: usuario.email,
  phone: usuario.telefone,
});
window.MauticWebChat?.open();
window.MauticWebChat?.openWithMessage('Preciso de ajuda com meu cadastro');
window.MauticWebChat?.close();
window.MauticWebChat?.toggle();
window.MauticWebChat?.reset();
```

O site pode sincronizar sua interface com os eventos `mautic-webchat:ready`, `mautic-webchat:open`, `mautic-webchat:close` e `mautic-webchat:error`. `destroy()` remove o `iframe`, os listeners e a API global.

## Integração com o Inbox e a IA

O plugin não duplica a lógica de atendimento. A conversa aparece com o badge **Web Chat** e pode ser assumida, transferida, adiada ou resolvida no Inbox. Mensagens humanas e da IA passam pelo mesmo transporte; notas internas não chegam ao visitante.

Quando o widget possui um agente inicial:

- a sessão é atribuída ao agente habilitado para a conta e para o canal Web Chat;
- o indicador exibe o nome do agente enquanto o Pi executa;
- documentos publicados, CMS, mercados permitidos e estágio do funil vêm da configuração do Inbox;
- `0` mantém respostas ilimitadas; um limite positivo pausa a sessão ao ser atingido;
- o agente pode encerrar a conversa, registrando **Closed by agent** no Inbox.

![Resposta da IA no widget](docs/screenshots/widget-final-conversation.jpg)

![Conversa encerrada pelo agente no Inbox](docs/screenshots/inbox-resolved-final.jpg)

## Segurança e privacidade

- cada widget aceita somente origens HTTPS cadastradas;
- o `iframe` recebe uma CSP `frame-ancestors` restrita aos domínios do widget;
- visitante e atendente usam tokens HMAC diferentes, vinculados à sessão e com expiração;
- o segredo interno do gateway nunca é enviado ao navegador;
- mensagens são persistidas antes da publicação em tempo real;
- o gateway escuta em `127.0.0.1` por padrão e limita payloads a 16 KiB;
- nome e e-mail podem criar ou vincular um contato, de acordo com a configuração do widget;
- endpoints públicos são stateless e não inicializam a sessão administrativa do Mautic;
- o loader e o HTML público podem receber cache curto no proxy sem armazenar APIs de sessão.

## Desenvolvimento e testes

Os testes abaixo não iniciam o kernel do Mautic nem alteram banco de dados:

```bash
npm ci
npm test
find . -path './node_modules' -prune -o -path './vendor' -prune -o -name '*.php' -print0 \
  | xargs -0 -n1 php -l
composer validate --strict
```

`npm test` verifica Svelte/TypeScript, produz os bundles, testa o protocolo do cliente e inicia o broker PHP numa porta efêmera para comprovar autenticação, digitação, leitura, isolamento de salas e replay limitado. Também comprova que mil atualizações de tela produzem um único recibo. Os testes não conectam ao banco. Testes de navegador devem usar uma conversa autorizada e confirmar visualmente os estados no widget e no Inbox.

## Demonstração end-to-end

[![Assistir à validação end-to-end](docs/video/mautic-webchat-e2e-cover.jpg)](docs/video/mautic-webchat-e2e.mp4)

[Assistir ao vídeo MP4 da validação da versão 1.0](docs/video/mautic-webchat-e2e.mp4)

O roteiro registrado cobre configuração, início da sessão, identificação, digitação do visitante, digitação nomeada da IA, confirmação de leitura, consulta do relatório da rodada, resposta contextual, limite ilimitado e encerramento pelo agente.

| Visitante digitando no Inbox | Agente digitando no site |
| --- | --- |
| ![Indicador do visitante](docs/screenshots/inbox-visitor-typing-final.jpg) | ![Indicador nomeado da IA](docs/screenshots/widget-agent-named-typing.jpg) |

| Confirmação de leitura | Resposta contextual no Inbox |
| --- | --- |
| ![Recibo de leitura](docs/screenshots/widget-read-receipt.jpg) | ![Resposta baseada no relatório](docs/screenshots/inbox-ai-answer.jpg) |

## Operação

- `GET /s/webchat/api/realtime/health` mostra a configuração e a saúde do gateway para administradores;
- `GET http://127.0.0.1:8790/health` mostra salas, conexões e uptime localmente;
- mensagens continuam disponíveis pelo histórico HTTP quando o gateway cai;
- desativar um widget impede novas sessões e preserva conversas existentes;
- a demonstração de cada widget fica em `/chat/demo/{publicKey}`.

## Licença

[GPL-3.0-or-later](LICENSE).

## SSE validation (1.2)

The PHP/SSE migration fixes a read-receipt feedback loop that could saturate FPM. See [incident and validation](docs/INCIDENT-2026-10-04.md), [deployment and index guide](docs/OPERATIONS.md) and the [paired Inbox 1.4 change](https://github.com/raphaelcangucu/mautic-inbox-bundle).

![Mobile widget with durable read receipt and live replies](docs/screenshots/widget-sse-mobile.jpg)

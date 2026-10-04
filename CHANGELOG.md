# Changelog

## 1.1.0 - 2026-10-04

- API pública `window.MauticWebChat` para abrir, fechar, alternar e reiniciar o widget em aplicações reativas.
- Identificação antecipada do visitante autenticado e preenchimento de mensagem ao abrir o atendimento.
- Eventos de prontidão, abertura, fechamento e erro para integração com o estado do aplicativo hospedeiro.

## 1.0.0 - 2026-10-04

- Primeiro canal de Web Chat em tempo real para o Mautic Omnichannel Inbox.
- Widget incorporável, sessões duráveis, indicador de digitação nomeado, presença e confirmações de entrega e leitura.
- Configuração de widgets, domínios, identidade visual, conta do Inbox e agente de IA inicial.
- Gateway WebSocket autenticado e recuperação HTTP.
- Loader stateless em `/chat/embed.js`, com `/chat/generate.js` preservado como alias de compatibilidade.
- Encerramento pela IA, estágio do contato, página de origem, UTMs e contador ilimitado ou configurável do Inbox.
- Documentação de arquitetura, implantação, operação e demonstração end-to-end validada em produção.

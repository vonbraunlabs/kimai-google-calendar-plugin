# ADR-0004: A UI segue o tema do Kimai, sem identidade visual própria

## Status

Aceita — 2026-09-28.

## Contexto

O fluxo de desenvolvimento deste repositório foi replicado do projeto Karajan, que tem uma
identidade visual própria (ADR-0001 do Karajan e skill `identidade-visual`: paleta com latão,
IBM Plex, tokens CSS). O plugin, porém, não é uma aplicação independente: suas telas são
renderizadas dentro do layout do Kimai (tema Tabler).

## Decisão

As telas do plugin estendem `base.html.twig` do Kimai e usam apenas as classes e componentes do
tema (cards, tabelas, formulários, ícones Font Awesome do Kimai). Não há tokens de cor, fontes ou
folhas de estilo próprias; estilos inline ficam restritos a ajustes de layout pontuais
(ex.: largura mínima de colunas da tabela de revisão). A skill `identidade-visual` do Karajan não é
replicada aqui.

## Alternativas consideradas

- **Aplicar a identidade do Karajan.** Descartada: destoaria do restante do Kimai para o usuário e
  quebraria com temas/modo escuro do próprio Kimai.

## Consequências

- Atualizações de tema do Kimai são herdadas automaticamente; mudanças no layout base do Kimai
  podem exigir ajustes nos templates (verificado pelo job `kimai-install` do CI e pelo
  `pre-pr-check`).

# ADR-0001: Nada é registrado sem revisão do usuário

## Status

Aceita — 2026-09-28.

## Contexto

A primeira versão do plugin sincronizava sozinha (botão ou cron): todo evento terminado virava um
registro de horas no projeto/atividade padrão do usuário, e alterações/cancelamentos no Google
atualizavam ou apagavam o registro. Ao testar com a agenda real, o Diego apontou que "importar ou
não" é pouco: o projeto e a atividade frequentemente são outros, a descrição precisa ser ajustada,
e parte dos eventos nem deve virar apontamento.

## Decisão

O usuário escolhe um período e recebe uma **tabela de revisão**. Em cada linha pode ajustar
início/fim, projeto, atividade e descrição; só as linhas **marcadas e enviadas** são registradas
(botão "Registrar selecionados").

- Projeto/atividade vêm pré-preenchidos quando identificáveis (ADR-0003); senão, em branco.
- Uma linha só vem pré-marcada se tiver projeto **e** atividade identificados e o evento já tiver
  terminado.
- Itens já registrados aparecem somente leitura e não podem ser registrados de novo. Depois de
  registrado, nada muda sozinho — alterações posteriores no Google não tocam no Kimai.
- Descrição padrão: `Participação na reunião '<título>'` para eventos com outros convidados
  (recursos como salas não contam); título puro para eventos sem convidados e para tarefas.
- Linhas com erro (validação do Kimai, falta de projeto etc.) voltam sinalizadas, preservando o
  que foi digitado.

## Alternativas consideradas

- **Sincronização automática (cron) só para itens identificados pelas regras.** Descartada: um
  apontamento feito à revelia com projeto errado custa mais para achar e corrigir do que para
  confirmar na tabela.
- **Manter a sincronização automática e adicionar edição depois.** Descartada pelo mesmo motivo e
  porque atualizar/apagar registros a partir do Google conflitaria com as edições do usuário.

## Consequências

- Removidos: comando de sincronização (cron), projeto/atividade padrão, "criar atividade a partir
  do título" e o resultado da última sincronização.
- O servidor nunca confia na pré-seleção: linhas que não vieram no POST não são registradas (bug
  encontrado e coberto por teste).
- Registros apagados manualmente no Kimai voltam a aparecer como pendentes na tabela.

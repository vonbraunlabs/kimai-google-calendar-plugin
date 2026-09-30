# ADRs (Architecture Decision Records)

Registra decisões de arquitetura, design ou processo que são caras de reverter depois de
implementadas — o tipo de escolha que, uma vez que várias telas/serviços passam a depender dela,
não se troca só editando um arquivo.

## Convenção

- Um arquivo por decisão: `NNNN-titulo-curto-em-kebab-case.md`, numeração sequencial (`0001`,
  `0002`, ...) — o número nunca é reaproveitado, mesmo que uma ADR seja superada.
- Estrutura mínima: **Status**, **Contexto**, **Decisão**, **Alternativas consideradas**,
  **Consequências**.
- **Status** é um destes: `Proposta` → `Aceita` → (eventualmente) `Superada por ADR-NNNN` /
  `Depreciada`. Uma ADR aceita não é reescrita quando a decisão muda — abre-se uma nova ADR que a
  supera, preservando o histórico de *por que* a decisão original foi tomada.
- Uma ADR documenta a decisão e o raciocínio; não é o lugar para a implementação em si (isso vive
  no código, com a ADR referenciada em comentário/commit quando fizer diferença).

## Índice

| ADR | Decisão |
|---|---|
| [0001](0001-revisao-manual-antes-de-registrar.md) | Nada é registrado sem revisão do usuário (sem sincronização automática) |
| [0002](0002-cliente-oauth-por-instalacao.md) | Um cliente OAuth por instalação, configurado pelo administrador |
| [0003](0003-identificacao-de-projeto-e-atividade.md) | Ordem de identificação de projeto e atividade |
| [0004](0004-ui-segue-o-tema-do-kimai.md) | A UI segue o tema do Kimai, sem identidade visual própria |
| [0005](0005-testes-com-kimai-como-dependencia-de-dev.md) | Testes com o Kimai como dependência de desenvolvimento, sem banco |
| [0006](0006-regras-de-mapeamento-por-palavra-inteira.md) | Palavras-chave das regras de mapeamento casam como palavra inteira |

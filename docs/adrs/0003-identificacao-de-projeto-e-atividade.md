# ADR-0003: Ordem de identificação de projeto e atividade

## Status

Aceita — 2026-09-28. Critério de casamento das regras de mapeamento (etapa 4) complementado pela
[ADR-0006](0006-regras-de-mapeamento-por-palavra-inteira.md).

## Contexto

Para a tabela de revisão (ADR-0001) ser útil, projeto e atividade precisam vir preenchidos sempre
que possível, de forma previsível para o usuário entender (e corrigir) por que algo foi escolhido.

## Decisão

Projeto primeiro, depois atividade, ambos pela mesma ordem — vence a primeira etapa que encontrar:

1. **código** (número do Kimai) no título;
2. **nome** no título;
3. **nome** na descrição (HTML convertido para texto);
4. primeira **regra de mapeamento** cuja palavra-chave esteja no título
   (`palavra => Projeto`, `palavra => Projeto / Atividade`, `palavra => / Atividade`; projeto e
   atividade por nome ou código).

- Código e nome casam como **palavra inteira**, sem diferenciar maiúsculas; havendo mais de um,
  vence o **mais longo** ("ACME Website" antes de "ACME"; "QA" não casa com "Quarterly").
- Candidatos limitados ao que o usuário pode apontar (consultas de formulário do Kimai, que aplicam
  as permissões de equipe). A atividade é buscada entre as do projeto escolhido e as globais, se o
  projeto as permitir.
- A tela mostra a origem de cada identificação ("identificado pelo código no título" etc.).
- Não identificado → em branco.

## Alternativas consideradas

- **Busca por substring simples.** Descartada: nomes curtos gerariam falsos positivos.
- **Regras antes dos nomes.** Descartada a pedido do Diego: código e nome são dados do próprio
  Kimai e mais confiáveis; regras são o último recurso.

## Consequências

- Mudar a ordem ou o critério de casamento muda o comportamento percebido pelos usuários — deve
  ser feito por nova ADR e refletido nos testes de `TargetResolverTest`.

# ADR-0006: Palavras-chave das regras de mapeamento casam como palavra inteira

## Status

Aceita — 2026-09-30. Complementa a [ADR-0003](0003-identificacao-de-projeto-e-atividade.md) no
critério de casamento da etapa 4 (regras de mapeamento); a ordem de identificação não muda.

## Contexto

A ADR-0003 definiu que código e nome casam como palavra inteira, mas não disse como casa a
palavra-chave de uma regra de mapeamento. A implementação usava busca por substring (`mb_stripos`),
e o texto de ajuda da tela dizia "palavra-chave contida no título". Com isso, a regra
`QA => Qualidade / Testes` casava com "QUALIDADE" e com "Quarterly" — o mesmo falso positivo que
levou a ADR-0003 a descartar substring para nomes. O Diego confirmou que isso está errado: todos
os critérios devem casar apenas por palavra inteira.

## Decisão

A palavra-chave de uma regra casa com o título pelo mesmo critério de código e nome: **palavra
inteira**, sem diferenciar maiúsculas. Uma "palavra" é delimitada por qualquer caractere que não
seja letra nem dígito (colchetes, espaço, pontuação, início/fim do texto).

- `QA` casa com `[GPV0374][QA] Estabelecimento de fluxo` e com `[GPV0377][Factum] Testes de QA`.
- `QA` **não** casa com `QUALIDADE`, `Quarterly` nem `QA2`.
- Palavras-chave com mais de uma palavra (`Code review`) ou com símbolos (`[GPV0374]`, `C++`)
  casam como sequência literal delimitada da mesma forma.
- O critério fica num único lugar (`Service/TextMatcher.php`), usado por código, nome e regras.
- Entre as regras que casam, continua valendo a **primeira** na ordem configurada (não a mais
  longa, como em código e nome) — o usuário controla a prioridade pela ordem das linhas.

## Alternativas consideradas

- **Manter substring e orientar o usuário a escrever palavras-chave mais longas.** Descartada:
  palavras-chave curtas como siglas de área ("QA", "RH", "TI") são justamente o caso de uso, e
  gerariam falsos positivos silenciosos na tabela de revisão.
- **Opção por regra (substring ou palavra inteira).** Descartada: complica a sintaxe das regras
  sem caso de uso concreto; quem precisar casar um prefixo pode escrever a palavra completa.

## Consequências

- Regras que dependiam de casar parte de uma palavra (ex.: `Deploy` para "Deployment") deixam de
  casar; o usuário precisa escrever a palavra inteira. Não há usuários em produção ainda.
- Mudanças futuras no critério de casamento valem para os três casos ao mesmo tempo e devem ser
  refletidas em `TextMatcherTest`.

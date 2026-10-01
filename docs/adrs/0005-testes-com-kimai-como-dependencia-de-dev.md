# ADR-0005: Testes com o Kimai como dependência de desenvolvimento, sem banco

## Status

Aceita — 2026-09-28.

## Contexto

O fluxo herdado do Karajan exige 80% de cobertura de linhas em toda PR. O código do plugin depende
de classes do Kimai (entidades, `TimesheetService`, repositórios, controller base) — algumas
`final` — e do Symfony. O Diego pediu que, se possível, o comportamento do Kimai fosse simulado
sem integração real, desde que não exigisse centenas de linhas de mock.

## Decisão

- `kimai/kimai` (Packagist) é **dependência de desenvolvimento**: fornece as classes para o
  PHPUnit gerar mocks com `createMock()` — não há banco, kernel nem Kimai rodando nos testes.
- Classes `final` do Kimai (`SystemConfiguration`, `TimesheetService`, `TrackingModeService`) são
  usadas **reais**, montadas com colaboradores simulados (`Tests/KimaiMocks.php`,
  `Tests/Fixtures.php`).
- O controller é testado com um container Symfony em memória (Twig, roteador, sessão, token,
  CSRF e formulários simulados).
- Migrations ficam fora da métrica; a instalação real é verificada pelo job `kimai-install` do CI
  (Kimai da versão fixada em `docker/Dockerfile`) e pelo `pre-pr-check`.
- Gate: `.github/scripts/coverage-gate.php` (mesmo script no CI e localmente).

## Alternativas consideradas

- **PHPUnit dentro da imagem Docker do Kimai com MySQL.** Mais fiel, porém mais lento e frágil
  (a imagem de produção não traz pcov/phpunit); mantido apenas como verificação de instalação.
- **Stubs escritos à mão das classes do Kimai.** Descartada: dezenas de classes a manter em
  sincronia com cada versão do Kimai.

## Consequências

- O dependabot de `composer` traz novas versões do Kimai; o CI valida o plugin contra elas
  (unitários com as classes novas + instalação no Docker quando a imagem for atualizada).
- Mudanças de comportamento dentro do Kimai que não alteram assinaturas (ex.: a checagem de
  permissão `create` no `saveNewTimesheet` da 2.6x) só aparecem se os testes exercitarem as classes
  reais — motivo para preferir as reais aos mocks quando forem `final` ou centrais.

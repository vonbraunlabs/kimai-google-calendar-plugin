# ADR-0002: Um cliente OAuth por instalação, configurado pelo administrador

## Status

Aceita — 2026-09-28.

## Contexto

O Google exige que todo aplicativo que lê agendas esteja registrado no Google Cloud (Client ID e
Client Secret identificam o **aplicativo**, não o usuário), com URIs de redirecionamento fixas,
HTTPS e domínio (IPs não são aceitos, só `localhost`). O primeiro painel da página misturava o
passo a passo do Google Cloud com o uso do plugin, e o Diego entendeu — com razão — que cada
usuário teria de cadastrar credenciais.

## Decisão

- O administrador registra o cliente OAuth **uma vez por instalação do Kimai** e informa as
  credenciais em *Sistema → Configurações → Google Calendar* (ou pelas variáveis
  `GOOGLE_CALENDAR_CLIENT_ID`/`GOOGLE_CALENDAR_CLIENT_SECRET`; a configuração do sistema tem
  precedência).
- Cada usuário só clica em "Conectar com o Google" e autoriza (escopos somente leitura). Tokens
  ficam criptografados com chave derivada do `APP_SECRET`; desconectar revoga o acesso no Google.
- O guia do Google Cloud aparece **apenas** para quem tem a permissão `system_configuration`;
  usuários comuns veem o guia de uso ou, se não configurado, o aviso para acionar um administrador.
- A URI de retorno não tem locale (`/google-calendar/oauth/callback`), pois o Google exige URI fixa.

## Alternativas consideradas

- **Endereço secreto iCal por usuário** (sem Google Cloud). Descartada por ora: não traz tarefas
  do Google Tasks, pode ser desativado por administradores do Workspace e o link funciona como
  senha.
- **Cliente OAuth único da Von Braun para todas as instalações.** Descartada: cada instalação tem
  seu domínio; exigiria um serviço intermediário e verificação do app pelo Google.

## Consequências

- Atrás de proxy reverso/túnel é obrigatório configurar `TRUSTED_PROXIES`/`TRUSTED_HOSTS`; sem isso
  a URI de retorno é gerada com `http://` e o Google recusa.
- Tela de consentimento "Externa" em modo de teste exige cadastrar os testadores e invalida a
  autorização a cada 7 dias; "Interna" (Workspace) não tem essa limitação.

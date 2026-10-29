# Site Donkey Head Cervejaria

Remodelação de www.donkeyheadcervejaria.com.br com o conteúdo do site atual (história, rótulos, galeria, contatos) e um **painel admin** para a equipe atualizar as cervejas e a **tap list** de cada unidade.

Funciona na hospedagem Linux da Locaweb (PHP 7.4+), **sem banco de dados**: o painel grava tudo em um arquivo JSON.

## Estrutura

```
site/                         tudo que vai para public_html
  index.php                   página do site
  admin/                      painel da equipe → www.donkeyheadcervejaria.com.br/admin/
  agenda/                     agenda pública de shows e feed .ics
  inc/dados.php               leitura/gravação dos dados (bloqueado na web)
  dados-iniciais/cervejas.json  32 rótulos importados do site antigo (bloqueado na web)
  dados/                      criado pelo painel no servidor: cervejas, tap list, senha e backups diários
  uploads/cervejas/           fotos de rótulos enviadas pelo painel
  uploads/galeria/            fotos da galeria enviadas pelo painel
  assets/                     CSS, JS, vídeo do chope, logo, rótulos e galeria (imagens otimizadas)
.github/workflows/deploy.yml  publica na Locaweb a cada push na main
```

`dados/` e `uploads/` **nunca** são enviados nem apagados pela publicação. O que a equipe faz no painel não se perde quando o site é atualizado.

## Painel (admin)

Endereço: `/admin/`

- **Usuários e funções:** cada pessoa tem login próprio. Funções: **Administrador** (tudo, inclusive usuários e importação), **Financeiro** (agenda e bandas com cachê/consumação, relatório), **Designer** (agenda, bandas e fotos, sem valores; galeria) e **Barman** (tap list e cervejas). A senha única antiga virou o usuário `admin`.
- **Tap List:** escolha a cerveja de cada torneira, separado por unidade (Aldeota e Maraponga), e toque em Salvar. O site atualiza na hora.
- **Cervejas:** cadastre, edite ou exclua rótulos. Dá para mandar a foto do rótulo direto do celular, porque ela é reduzida automaticamente. Para tirar um rótulo do catálogo sem apagá-lo, desmarque "Mostrar no catálogo".
- **Galeria:** envie várias fotos de uma vez pelo celular, com legenda opcional (ex.: o nome do prato). As fotos novas entram no início, e as 12 primeiras aparecem direto no site. Dá para reordenar, editar a legenda e excluir. As fotos são reduzidas no próprio celular antes do envio.
- Segurança: senha com hash, proteção CSRF e bloqueio de 15 minutos após 5 senhas erradas.
- Se o painel disser que não consegue gravar, dê permissão de escrita (755 ou 775) às pastas `dados` e `uploads/cervejas` no Gerenciador de Arquivos da Locaweb.
- Esqueceu a senha? O administrador redefine em "Usuários". Se for o próprio administrador, apague `dados/usuarios.json` e `dados/admin.json` pelo FTP e crie um novo no `/admin/`.

## Agenda de shows

- **Bandas** (`/admin/?acao=bandas`): nome, tributos (vários, um por linha), cachê e consumação padrão, Instagram, contato e fotos. A foto original é guardada sem perder qualidade (botão "Baixar todas as fotos" gera um .zip para o designer); o site usa cópias reduzidas.
- **Link da banda:** em cada banda, "Criar link para a banda" gera um endereço secreto (`/banda/?t=…`) para enviar por WhatsApp. Por ele a própria banda envia e exclui fotos, escolhe a principal e corrige o Instagram, sem senha e sem ver mais nada. "Criar link novo" cancela o anterior. O painel mostra quando a banda atualizou pela última vez.
- **Agenda** (`/admin/?acao=agenda`): data, horário (padrão configurável), unidade, banda, tributo, cachê, consumação e situação (a confirmar, confirmado, pago, cancelado). Só **confirmado** e **pago** aparecem no site.
- **Financeiro** (`/admin/?acao=relatorio`): totais do mês por unidade (cachês, consumação, pagos e a pagar) e planilha CSV.
- **Site:** seção "Agenda" na página inicial (próximos 6 shows) e página `/agenda/` em formato de calendário do mês (estilo Google Agenda, com cor por unidade; toque no dia abre banda, tributo e horário), lista dos próximos shows e filtro por unidade. Cachê e consumação nunca aparecem para o público.
- **Google Agenda:** `/agenda/ics.php` (pública) e o link da equipe (com valores), criado em Agenda → Configurações. Para importar o que já existe: Usuários → Importar Google Agenda (endereço secreto iCal ou arquivo .ics, uma agenda por vez, escolhendo a unidade dela), com revisão antes de gravar. Só os shows **de hoje em diante** entram na agenda. Os eventos antigos servem para cadastrar as bandas e completar tributos, cachê e consumação: quando o evento traz só o nome da banda, vale o tributo completo escrito em outro evento dela. Reservas, contas, fretes, pedidos de barril e dias "sem música" são ignorados, e erros de digitação no nome da banda ("Black Pepers" e "Black Peppers") viram um cadastro só.
- Dados em `dados/agenda.json` (com backup diário) e fotos em `uploads/bandas/`. Limites de envio em `site/admin/.user.ini`.

## Tap list na TV

Endereços (tela cheia, TV na horizontal):

- Aldeota: `https://www.donkeyheadcervejaria.com.br/tv/?unidade=aldeota`
- Maraponga: `https://www.donkeyheadcervejaria.com.br/tv/?unidade=maraponga`

A página busca a tap list a cada minuto, então as mudanças aparecem sozinhas. Fundo claro, cartões em 2 colunas com rótulo, nome, estilo, álcool, IBU e preço de 300 ml, agrupados da mais leve para a mais forte (grupo automático pelo estilo ou escolhido no painel, em "Grupo na TV"). As cervejas são divididas em telas pelos grupos (no máximo 8 por tela), que alternam a cada 15 s. Se a internet cair, mantém a última lista na tela. O desenho fica em `site/tv/desenho.js`, o mesmo usado na imagem.

**Imagem para vídeo (YouTube):** no painel, aba Tap List, o botão **Gerar imagem para a TV** abre `/tv/imagem.php?unidade=aldeota|maraponga`, que monta uma imagem 1920×1080 (PNG) com a tap list atual, pronta para baixar. Gera uma imagem por tela (ex.: 1/2 leves e maltadas, 2/2 lupuladas e fortes).

**Aparelho:** o Roku não tem navegador de verdade. Use um Fire TV Stick ou um Chromecast com Google TV com o app **Fully Kiosk Browser**, configurado com o endereço acima como página inicial e para abrir ao ligar.

## Tap list automática pelo xMenu

Com autorização do suporte do xMenu, a tap list da **Aldeota** (loja 20476) e da **Maraponga** (loja 20409) vem direto da categoria "TAP LIST" do cardápio xMenu. O barman atualiza só no xMenu, e o site, a TV e os preços acompanham em até 1 minuto.

- Ligação unidade → loja do xMenu: `DH_XMENU_LOJAS` em `site/inc/xmenu.php`.
- **Cadastro automático (só Aldeota):** quando uma cerveja nova aparece na tap list do xMenu da Aldeota, o site a cadastra sozinho no catálogo, com nome, estilo, ABV, IBU, descrição e a foto do produto no xMenu. No painel ela aparece com a marca "Cadastrada pelo xMenu: revise". Edições feitas no painel não são sobrescritas. Se for excluída no painel enquanto ainda estiver no xMenu, ela é cadastrada de novo; para escondê-la, desmarque "Mostrar no catálogo".
- O site junta cada cerveja do xMenu com o cadastro do painel (foto e descrição) pelo nome, sem o tamanho. Se o nome for diferente, preencha **Nome no xMenu** na cerveja. As que não tiverem cadastro aparecem com o logo e um aviso no painel.
- O resultado fica em cache por 1 minuto em `dados/xmenu-<unidade>.json`. Se o xMenu cair, o site usa a última lista lida.
- Uma unidade sem loja no xMenu usaria a tap list montada no painel.

## Publicar na Locaweb

Antes de tudo, **faça backup** do `public_html` atual.

**Manual:** envie o conteúdo da pasta `site/`, incluindo os arquivos `.htaccess`, para `public_html`. Os arquivos do site antigo podem ficar, porque o `index.php` tem prioridade e o `index.html` antigo redireciona para a nova home.

**Automático:** no GitHub, abra Settings → Secrets and variables → Actions e crie:

| Secret | Valor (Painel Locaweb → Hospedagem → FTP) |
|---|---|
| `LOCAWEB_FTP_HOST` | endereço do FTP |
| `LOCAWEB_FTP_USER` | usuário do FTP |
| `LOCAWEB_FTP_PASSWORD` | senha do FTP |
| `LOCAWEB_FTP_DIR` | opcional; padrão `public_html/` |

Toda alteração em `site/` na branch `main` é publicada sozinha. A hospedagem só aceita FTP comum (sem FTPS). Antes de cada publicação é feito um backup completo do servidor, que fica 90 dias nos "Artifacts" da execução em Actions.

## Testar no computador

```
php -S localhost:8000 -t site
```
Depois abra http://localhost:8000 e http://localhost:8000/admin/.

## O que mudou em relação ao site antigo

- Visual novo e responsivo, sem Bootstrap/jQuery, e com aviso de 18+.
- O vídeo do topo era um GIF de 91 MB e virou um MP4 de menos de 1 MB. A galeria e os rótulos também foram otimizados (de 108 MB para cerca de 11 MB no total).
- A tap list fica dentro de **Nossas cervejas**: filtros "Engatadas na Aldeota" e "Engatadas na Maraponga", selo "Engatada" e, nas abas de engatadas, o preço de 300 ml da unidade no cartão da cerveja (os outros tamanhos aparecem nos detalhes). Cerveja engatada que não está no catálogo aparece com os dados do xMenu.
- Textos mantidos. Foram corrigidos só trechos duplicados por engano no HTML antigo (em Rye Se Lascar, West Coast Chocolate Stout e Pele Encarnada) e alguns erros de digitação.
- As fichas mostram só ABV e IBU (SRM e EBC foram removidos).

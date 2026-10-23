# Site Donkey Head Cervejaria

Remodelação de www.donkeyheadcervejaria.com.br com o conteúdo do site atual (história, rótulos, galeria, contatos) e um **painel admin** para a equipe atualizar as cervejas e a **tap list** de cada unidade.

Funciona na hospedagem Linux da Locaweb (PHP 7.4+), **sem banco de dados**: o painel grava tudo em um arquivo JSON.

## Estrutura

```
site/                         tudo que vai para public_html
  index.php                   página do site
  admin/                      painel da equipe → www.donkeyheadcervejaria.com.br/admin/
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

- **Primeiro acesso:** a tela pede para criar a senha. Abra o `/admin/` **logo depois de publicar**, porque quem chegar primeiro define a senha.
- **Tap List:** escolha a cerveja de cada torneira, separado por unidade (Aldeota e Maraponga), e toque em Salvar. O site atualiza na hora.
- **Cervejas:** cadastre, edite ou exclua rótulos. Dá para mandar a foto do rótulo direto do celular, porque ela é reduzida automaticamente. Para tirar um rótulo do catálogo sem apagá-lo, desmarque "Mostrar no catálogo".
- **Galeria:** envie várias fotos de uma vez pelo celular, com legenda opcional (ex.: o nome do prato). As fotos novas entram no início, e as 12 primeiras aparecem direto no site. Dá para reordenar, editar a legenda e excluir. As fotos são reduzidas no próprio celular antes do envio.
- Segurança: senha com hash, proteção CSRF e bloqueio de 15 minutos após 5 senhas erradas.
- Se o painel disser que não consegue gravar, dê permissão de escrita (755 ou 775) às pastas `dados` e `uploads/cervejas` no Gerenciador de Arquivos da Locaweb.
- Esqueceu a senha? Apague `dados/admin.json` pelo FTP e crie uma nova no `/admin/`.

## Tap list na TV

Endereços (tela cheia, TV na horizontal):

- Aldeota: `https://www.donkeyheadcervejaria.com.br/tv/?unidade=aldeota`
- Maraponga: `https://www.donkeyheadcervejaria.com.br/tv/?unidade=maraponga`

A página busca a tap list a cada minuto, então as mudanças aparecem sozinhas. Mostra número da torneira, rótulo, nome, estilo, ABV, IBU e preços de 300 ml e 1 L. Se houver mais de 12 torneiras, alterna as páginas a cada 15 s. Se a internet cair, mantém a última lista na tela.

**Aparelho:** o Roku não tem navegador de verdade. Use um Fire TV Stick ou um Chromecast com Google TV com o app **Fully Kiosk Browser**, configurado com o endereço acima como página inicial e para abrir ao ligar.

## Tap list automática pelo xMenu

Com autorização do suporte do xMenu, a tap list da **Aldeota** (loja 20476) vem direto da categoria "TAP LIST" do cardápio xMenu. O barman atualiza só no xMenu, e o site, a TV e os preços acompanham em até 1 minuto.

- Ligação unidade → loja do xMenu: `DH_XMENU_LOJAS` em `site/inc/xmenu.php`. Para ligar a Maraponga, basta adicionar o código da loja dela.
- O site junta cada cerveja do xMenu com o cadastro do painel (foto e descrição) pelo nome, sem o tamanho. Se o nome for diferente, preencha **Nome no xMenu** na cerveja. As que não tiverem cadastro aparecem com o logo e um aviso no painel.
- O resultado fica em cache por 1 minuto em `dados/xmenu-<unidade>.json`. Se o xMenu cair, o site usa a última lista lida.
- Unidades sem xMenu (hoje, a Maraponga) usam a tap list montada no painel.

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

Toda alteração em `site/` na branch `main` é publicada sozinha. Se a conexão falhar, troque `protocol: ftps` por `ftp` no `deploy.yml`.

## Testar no computador

```
php -S localhost:8000 -t site
```
Depois abra http://localhost:8000 e http://localhost:8000/admin/.

## O que mudou em relação ao site antigo

- Visual novo e responsivo, sem Bootstrap/jQuery, e com aviso de 18+.
- O vídeo do topo era um GIF de 91 MB e virou um MP4 de menos de 1 MB. A galeria e os rótulos também foram otimizados (de 108 MB para cerca de 11 MB no total).
- Nova seção **Tap List** por unidade e o selo "Engatada" nos rótulos.
- Textos mantidos. Foram corrigidos só trechos duplicados por engano no HTML antigo (em Rye Se Lascar, West Coast Chocolate Stout e Pele Encarnada) e alguns erros de digitação.
- As fichas mostram só ABV e IBU (SRM e EBC foram removidos).

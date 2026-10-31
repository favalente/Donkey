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

- **Usuários e funções:** cada pessoa tem login próprio. Funções: **Administrador** (tudo, inclusive usuários e importação), **Financeiro** (agenda e bandas com cachê/consumação, relatório), **Designer** (só visualiza: calendário da agenda e bandas com nome, tributo, Instagram e fotos para baixar; não edita nada) e **Barman** (tap list e cervejas). A senha única antiga virou o usuário `admin`.
- **Tap List:** escolha a cerveja de cada torneira, separado por unidade (Aldeota e Maraponga), e toque em Salvar. O site atualiza na hora.
- **Cervejas:** cadastre, edite ou exclua rótulos. Dá para mandar a foto do rótulo direto do celular, porque ela é reduzida automaticamente. Para tirar um rótulo do catálogo sem apagá-lo, desmarque "Mostrar no catálogo".
- **Galeria:** envie várias fotos de uma vez pelo celular, com legenda opcional (ex.: o nome do prato). As fotos novas entram no início, e as 12 primeiras aparecem direto no site. Dá para reordenar, editar a legenda e excluir. As fotos são reduzidas no próprio celular antes do envio.
- **Aparência:** tema claro (fundo creme, texto escuro, letras grandes) para leitura fácil de dia. No rodapé, **🌙 Modo escuro** troca o tema, e a escolha fica lembrada em cada aparelho. O site público continua escuro.
- Segurança: senha com hash, proteção CSRF e bloqueio de 15 minutos após 5 senhas erradas. "Manter conectado neste aparelho" (marcado por padrão) deixa o painel aberto por 180 dias; "Sair" ou trocar a senha desconecta.
- Se o painel disser que não consegue gravar, dê permissão de escrita (755 ou 775) às pastas `dados` e `uploads/cervejas` no Gerenciador de Arquivos da Locaweb.
- Esqueceu a senha? O administrador redefine em "Usuários". Se for o próprio administrador, apague `dados/usuarios.json` e `dados/admin.json` pelo FTP e crie um novo no `/admin/`.

## Agenda de shows

- **Bandas** (`/admin/?acao=bandas`): nome, tributos (vários, um por linha), cachê e consumação padrão, Instagram, contato e fotos. A foto original é guardada sem perder qualidade (botão "Baixar todas as fotos" gera um .zip para o designer); o site usa cópias reduzidas.
- **Link da banda:** em cada banda, "Criar link para a banda" gera um endereço secreto (`/banda/?t=…`) para enviar por WhatsApp. Por ele a própria banda envia e exclui fotos, escolhe a principal, corrige o Instagram e informa a chave Pix (e o nome de quem recebe) para o cachê; no painel, o Pix aparece só para administrador e financeiro, no cadastro da banda e no quadro do show, com botão "Copiar Pix", sem senha e sem ver mais nada. "Criar link novo" cancela o anterior. O painel mostra quando a banda atualizou pela última vez.
- **Agenda** (`/admin/?acao=agenda`): data, horário (padrão configurável), unidade, banda, tributo, cachê, consumação e situação (confirmado, pago, cancelado; o show só entra na agenda quando está fechado). Cancelado não aparece no site.
- **Pastas do Google Drive:** ao abrir Bandas (no máximo a cada 10 min, ou no botão "Verificar pastas no Google Drive agora"), o site lê a pasta pública das bandas. Pasta nova de banda já cadastrada é ligada sozinha (sai o "Sem pasta", que marca banda sem pasta de arte no Drive; fotos enviadas não contam); pasta sem banda cadastrada é avisada, com o botão "Cadastrar esta banda" para admin/financeiro. Pasta principal: `DH_DRIVE_PASTA` em `site/inc/agenda.php`.
- **Calendário no painel:** em Agenda e em Novo show aparece o mês inteiro, como no Google Agenda (cor por unidade; pago com ✓ verde; cancelado riscado). Tocar no dia abre/preenche um show novo naquela data; tocar no show abre Editar ou **Duplicar** (copia banda, tributo, horário, unidade e valores; falta só escolher o dia).
- **Financeiro** (`/admin/?acao=relatorio`): totais do mês por unidade (cachês, consumação, pagos e a pagar) e planilha CSV.
- **Site:** seção "Agenda" na página inicial (próximos 6 shows) e página `/agenda/` em formato de calendário do mês (estilo Google Agenda, com cor por unidade; toque no dia abre banda, tributo e horário), lista dos próximos shows e filtro por unidade. Cachê e consumação nunca aparecem para o público.
- **Google Agenda:** `/agenda/ics.php` (pública) e o link da equipe (com valores), criado em Agenda → Configurações. Para importar o que já existe: Usuários → Importar Google Agenda (endereço secreto iCal ou arquivo .ics, uma agenda por vez, escolhendo a unidade dela), com revisão antes de gravar. Só os shows **de hoje em diante** entram na agenda. Os eventos antigos servem para cadastrar as bandas e completar tributos, cachê e consumação: quando o evento traz só o nome da banda, vale o tributo completo escrito em outro evento dela. Reservas, contas, fretes, pedidos de barril e dias "sem música" são ignorados, e erros de digitação no nome da banda ("Black Pepers" e "Black Peppers") viram um cadastro só.
- Dados em `dados/agenda.json` (com backup diário) e fotos em `uploads/bandas/`. Limites de envio em `site/admin/.user.ini`.

## Orçamentos

Painel → **📄 Orçamentos** (administrador e financeiro): a proposta que vai antes do contrato, no mesmo visual do contrato (timbre, nome e CPF/CNPJ do cliente, data do evento, itens com valor unitário, quantidade e total, desconto em R$ abatido no fim, pagamento Pix antecipado, validade, dados bancários e assinatura). Todos os campos são opcionais.

- **Cliente da base:** o campo "Cliente da base" puxa nome e CPF/CNPJ (e o WhatsApp e o e-mail para envio, que não aparecem na proposta); cliente novo com CPF/CNPJ válido entra na base ao salvar.
- **Itens sugeridos:** chope da tabela do Chope Delivery (já com o valor do litro), tirador de chope, copos personalizados e frete; o total é valor × quantidade ("300L" conta como 300) ou digitado.
- **Envio:** PDF, link pelo WhatsApp ou e-mail com o PDF anexo. Pelo link (`/orcamento/?t=...`) o cliente vê, baixa e **aprova** (a equipe recebe o aviso por e-mail).
- **Gerar pedido a partir deste orçamento (eu preencho):** cria o contrato de venda de chope com comodato (rascunho, você revisa e gera para assinatura) com o cliente, o chope (itens com quantidade em litros, ex.: "50 L"), o frete, o desconto e os demais itens como adicionais, pelo preço do orçamento.
- **Gerar pedido para cliente preencher:** gera o link do pedido com os mesmos itens e preços travados. O cliente só confere o pedido, completa os dados (CPF ou CNPJ, endereço, contato, horário), anexa o comprovante do Pix e assina. Se o orçamento não tiver frete, ele escolhe Fortaleza ou outra cidade como no pedido normal.
- Padrões (validade e dados bancários) em "⚙ Padrões do orçamento". Dados em `dados/orcamentos.json`.

## Brassagem

O caderno de produção em versão digital (painel → 🧪 Brassagem). A fábrica é única e atende as duas sedes.

- **Tanques:** cadastre em ⚙ Tanques (nome e capacidade). A litragem do caderno, medida em cm, aparece também em litros efetivos (cm × 7,5). Tanque com histórico não se exclui: marque "Fora de uso".
- **Tanques virtuais:** a tela mostra cada tanque com o lote que está nele (cerveja, lote, dia da produção, últimas medidas de Brix, pressão e temperatura e a última anotação) ou "Livre".
- **Caderno do lote:** o cabeçalho do caderno de papel (sede para a qual é o lote, cerveja escolhida do cadastro de Cervejas, ou "Outra" digitada se ainda não estiver lá, data, lote, levedura, tanque, PF e OG em °Brix com a litragem em cm, Obs.) e o acompanhamento da fermentação e maturação: data, Brix, pressão (kg), temperatura (°C) e anotação livre, com quem anotou. Dá para corrigir ou apagar uma anotação e imprimir o caderno.
- **Fluxo:** Fermentando → Passar para maturação → Finalizar lote (libera o tanque). Um tanque recebe um lote por vez; o número do lote não se repete.
- **Histórico e comparação:** "+ Caderno antigo" registra um lote que já saiu do tanque (finalizado, sem ocupar o tanque). Em "Comparar lotes por cerveja" (ou 📈 no caderno), todos os lotes da cerveja: curva do Brix lido por dia (os 3 mais recentes em cor, os anteriores em cinza, a FG da receita tracejada) e tabela com PF, OG, FG lida, FG real, álcool, dia em que chegou na FG e dias no tanque.
- **Cópia e importação:** em ⚙ Tanques dá para baixar uma cópia de todos os cadernos (.json) e importar cadernos de um arquivo .json (por exemplo, os cadernos de papel transcritos). Lotes com número que já existe ficam de fora.
- **Acesso:** administrador e o perfil **Cervejaria** (que só vê a Brassagem e as Receitas).
- Dados em `dados/brassagem.json` (só no servidor), com cópia diária em `dados/backup`.

## Receitas

Uma receita para cada cerveja do cadastro (painel → 📖 Receitas), só com o que a fábrica usa: tamanho do lote, insumos, pré-fervura, OG e FG (em °Brix, como no refratômetro), litragem da lavagem, cor em EBC (com SRM entre parênteses), ABV, IBU e, opcional, o print da receita.

- **Importar do BeerSmith:** o arquivo **.bsmx** (pode ter várias receitas) ou BeerXML. O .bsmx guarda os ingredientes e o equipamento, mas não os números que o BeerSmith calcula na tela; OG, pré-fervura, FG, ABV e cor são calculados do mesmo jeito (diferença de alguns décimos para o BeerSmith), o IBU vem pronto do arquivo e a lavagem vem da anotação no nome da água ("Agua (lavagem 37L)") ou é calculada. Tudo pode ser corrigido à mão.
- Cada receita é ligada à cerveja de nome parecido do cadastro (ignora litragem, eficiência e ano). Duas receitas do mesmo arquivo para a mesma cerveja: a segunda fica em "Sem cerveja ligada". Importar de novo a receita de uma cerveja substitui a atual e guarda a anterior em "versões".
- **°Brix do refratômetro:** pré-fervura e OG do BeerSmith (°Plato) são convertidas para a leitura do refratômetro (°Brix = °Plato × 1,04), com o °Plato e a densidade embaixo.
- **Ordem dos insumos:** água e sais sempre no começo da lista (água primeiro) e os da maturação (dry hop) sempre no fim, com fundo verde claro; a US-05 aparece como 250 g (pacote grande).
- **FG no refratômetro:** a FG da receita é a leitura esperada no refratômetro no fim da fermentação, já com a correção de álcool (fórmula de Sean Terrill, fator do mosto 1,04); a densidade real aparece embaixo. No caderno, cada Brix anotado depois da brassagem mostra a densidade corrigida e o álcool até ali, a partir da OG do caderno (ou da receita).
- **Na Brassagem**, a cerveja escolhida traz a receita: a levedura entra sozinha, o caderno mostra a receita e a pré-fervura e a OG da receita ao lado das medidas. O lote guarda uma cópia da receita do dia.
- Acesso: administrador e o perfil Cervejaria. Dados em `dados/receitas.json` e prints em `dados/receitas/` (só no servidor), com cópia diária em `dados/backup`.

## Contratos com assinatura eletrônica

Painel → **Contratos** (administrador e financeiro). Dois modelos: **Evento corporativo no pub** (texto do contrato jurídico da casa, com o Anexo I – Menu) e **Venda de chope com comodato de equipamentos** (equipamentos com valor de reposição já preenchidos).

1. Preencha o formulário (dá para salvar rascunho) e toque em **Gerar para assinatura**: o texto é congelado e ganha número (DH-2026-001) e **código de integridade** (SHA-256).
2. **A Donkey assina** no painel, com a senha do administrador ou do financeiro (fica registrado qual usuário assinou). O nome e o CPF de quem assina ficam em Contratos → Configurações (só no servidor, nunca no código).
Contrato assinado tem **✉️ Reenviar cópia** (para contato@ e a lista de cópia). Na lista, cada contrato é um cartão com os botões do momento (assinar, ver, enviar pelo WhatsApp, editar, duplicar) e **🗑 Excluir**, que apaga de vez (útil para testes; contrato assinado, só o administrador exclui).

2b. **Editar antes do cliente assinar:** no contrato, **✏️ Editar contrato** volta para o formulário com o mesmo número e o mesmo link (o cliente vê "Contrato em ajuste" enquanto isso). Ao gerar de novo, o texto ganha novo código de integridade, a assinatura da Donkey é refeita e o código de e-mail pedido antes da edição deixa de valer. A versão anterior fica guardada no registro do contrato. Depois que o cliente assina, só cancelando e duplicando.
3. **O cliente** recebe o link (WhatsApp ou e-mail), lê, informa nome e CPF, recebe um **código de 6 números no e-mail** e assina.
4. Com as duas assinaturas, os dois lados recebem a cópia por e-mail (e também os **e-mails de cópia** cadastrados em Contratos → Configurações, que recebem só essa cópia final; os avisos do andamento vão apenas para contato@). A página do contrato tem o **Relatório de assinaturas** (horário, IP, aparelho, forma de autenticação) e tem o botão **⬇ Baixar PDF**. A cópia do contrato assinado chega por e-mail **em PDF anexo** (com o relatório de assinaturas). O PDF é gerado pelo [Dompdf](https://github.com/dompdf/dompdf) (LGPL), instalado em `site/inc/vendor` pelo `composer.json` da raiz (só com a fonte DejaVu Sans; a pasta `inc` é bloqueada para a web).
5. Qualquer pessoa confere a autenticidade em `/contrato/verificar.php` pelo número ou pelo código de integridade.

**Pedido de chope (o cliente preenche):** o painel cria o link; o cliente escolhe rótulos e litros (valores de evento cadastrados em Cervejas), chope em comodato opcional, dados do cabeçalho, data e **horário de entrega estimado**, data de devolução (no máximo no dia seguinte ao evento) e se a entrega é em Fortaleza (frete de R$ 40) ou em outra cidade (ele digita o frete combinado com o vendedor). Pode pedir **copos personalizados** ("Deseja copos personalizados?": pacotes de 50 copos por R$ 40, quantos quiser; valor em `DH_COPOS`, em `inc/contratos.php`). Pode aplicar um **cupom de desconto** (Contratos → 🏷 Cupons: porcentagem ou valor, validade e limite de usos; vale sobre o chope, não sobre o frete). O cliente **anexa o comprovante de pagamento** (foto ou PDF, em `dados/comprovantes/`); o código SHA-256 do arquivo entra no texto do contrato, que só é gerado com ele. Os dados para pagamento (chave Pix) ficam em Contratos → Configurações. Nos contratos que você preenche (e ao editar qualquer um antes da assinatura), o formulário também tem o campo **Comprovante de pagamento** para anexar, trocar ou tirar o arquivo.

Validade: assinatura eletrônica entre as partes (MP 2.200-2/2001, art. 10, § 2º; Lei 14.063/2020). Os e-mails saem de contato@donkeyheadcervejaria.com.br pelo **SMTP autenticado da Locaweb** (email-ssl.com.br, porta 587, TLS): a senha da caixa é cadastrada em Contratos → Configurações e fica só em `dados/email.json` (sem senha, o sistema tenta o `mail()` do PHP). A mesma tela tem **Enviar e-mail de teste** e o histórico dos últimos envios, com o erro quando algum falha. Dados em `dados/contratos.json`, com cópia diária em `dados/backup/`.

### Chope delivery (site)

A página inicial tem o card **Chope Delivery** ("Leve o nosso chope para o seu evento!"), que abre `/chope/`: tabela de preços **automática**, só com as cervejas **engatadas agora** (tap list do xMenu). O valor do litro para evento é o litro do bar com **30% de desconto na Premium Lager e 35% nas especiais**, arredondado para cima até o próximo R$ 0,50 (`dh_preco_delivery`, em `inc/contratos.php`). A base é o **"Preço 1 litro na Aldeota"** do cadastro de cada cerveja (Cervejas), nunca o preço da Maraponga: quando a cerveja está engatada na Aldeota, esse campo se atualiza sozinho com o preço do xMenu de lá; engatada só na Maraponga, vale o que está no cadastro (os vazios foram preenchidos uma vez pela planilha de produtos da Aldeota, `DH_PRECOS_ALDEOTA_PLANILHA`). Sem preço, a cerveja não entra. O formulário de pedido do cliente usa a mesma lista. A página mostra ainda o que está incluso, frete, copos e como funciona. O botão **🍺 Fazer meu pedido** (`chope/pedido.php`) abre um pedido em branco e leva o cliente ao mesmo formulário do link do painel: ele escolhe rótulos e litros, preenche os dados, paga pelo Pix, anexa o comprovante e assina; a Donkey só assina no painel para aprovar (o cartão leva a etiqueta 🌐 Pelo site). O mesmo visitante reaproveita o pedido aberto, há limite de 5 pedidos por hora por endereço, e pedidos do site nunca preenchidos não aparecem no painel e somem depois de 3 dias. O WhatsApp fica para dúvidas.

### Produtos e preços

Contratos → **🧾 Produtos e preços**: o que se vende junto com o chope (Frete Fortaleza, Copos personalizados, Tirador de chope e o que mais for cadastrado), com nome, detalhe, preço e "cobrado por". Os marcados em **Perguntar no pedido** viram perguntas no pedido do cliente ("Deseja tirador de chope?", com quantidade). Os preços valem para o pedido, a página do Chope Delivery, as sugestões do orçamento e o formulário do contrato; o preço fica gravado no contrato na hora do pedido (`inc/produtos.php`, dados em `dados/produtos.json`).

### Relatórios de vendas de chope

Painel → **📊 Relatórios** (administrador e financeiro), no padrão do painel do Reverti: período no topo (últimos 12/6/3 meses, este ano, ano passado, todo o histórico ou datas), cartões de faturamento, pedidos, litros, ticket médio, valor médio do litro, clientes, empresas e chope consignado (com comparação com o mesmo período do ano anterior), **💡 Onde melhorar** (leituras automáticas dos números), faturamento por mês, época do ano, tamanho do pedido, dia da semana, rótulos, antecedência, bairros/cidades, ranking de clientes (top 5/10/20) e clientes sem comprar há 6/12/24 meses, com botão de WhatsApp.

Os dados vêm dos contratos de comodato assinados no site e do **histórico da ZapSign**: no fim da página de Relatórios, envie a planilha .xlsx de respostas do modelo de contrato. O importador (`inc/vendas.php`) limpa o texto livre (rótulo escrito de vários jeitos, litragem, valores em R$ ou $, datas com ano errado, total trocado com o valor do litro), marca contratos repetidos e lista o que foi corrigido para conferir. Reenviar a planilha atualiza, sem duplicar. Os clientes entram na base de Clientes. Tudo fica só no servidor (`dados/vendas-historico.json`).

### Clientes

Painel → **Clientes**: cada contrato registra ou atualiza o cliente (pelo CPF/CNPJ) em `dados/clientes.json`. Dá para buscar, chamar no WhatsApp, criar um **pedido de chope já preenchido** com os dados dele e baixar a planilha. Os dados dos pedidos servem para análise interna de vendas; o pedido não pede mais autorização de novidades. A **planilha para prospecção** traz só quem autorizou antes (LGPD), e "Parar novidades" retira a autorização quando o cliente pedir. Ninguém puxa dados digitando um CPF no site: o preenchimento automático só acontece no link criado pelo painel.

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

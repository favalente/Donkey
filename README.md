# Site Donkey Head Cervejaria

Site novo de www.donkeyheadcervejaria.com.br. É um site estático (HTML, CSS e JS, sem banco de dados), hospedado na Locaweb.

## Estrutura

```
site/                  tudo que vai para a hospedagem (public_html)
  index.html           página única: história, cervejas, unidades, chope delivery, eventos
  .htaccess            força HTTPS + www e ativa cache
  assets/css/style.css
  assets/js/main.js    aviso de 18+, menu mobile, animações
  assets/img/          logo, imagem de compartilhamento (og.png)
.github/workflows/deploy.yml   publica na Locaweb a cada push na branch main
```

Para ver o site no computador, abra `site/index.html` no navegador.

## Publicar na Locaweb

### Opção A: manual
1. Painel da Locaweb → Hospedagem → **Gerenciador de Arquivos** (ou FTP com FileZilla).
2. **Faça backup** do conteúdo atual de `public_html`.
3. Envie todo o conteúdo da pasta `site/` (incluindo o `.htaccess`) para `public_html`.

### Opção B: automática (GitHub Actions)
No GitHub, abra o repositório → Settings → Secrets and variables → Actions e crie:

| Secret | Valor (Painel Locaweb → Hospedagem → FTP) |
|---|---|
| `LOCAWEB_FTP_HOST` | endereço do FTP (ex.: `ftp.donkeyheadcervejaria.com.br`) |
| `LOCAWEB_FTP_USER` | usuário do FTP |
| `LOCAWEB_FTP_PASSWORD` | senha do FTP |
| `LOCAWEB_FTP_DIR` | opcional; padrão `public_html/` |

A partir daí, toda alteração em `site/` que entrar na branch `main` é publicada sozinha. Também dá para rodar manualmente em Actions → "Publicar na Locaweb" → Run workflow.
Se a conexão falhar, troque `protocol: ftps` por `protocol: ftp` em `deploy.yml`.

## Conferir antes de publicar

As informações foram tiradas de fontes públicas (imprensa local, Untappd, cadastros). Confirme:

- [ ] Telefone/WhatsApp **(85) 98212-4149** (usado em todos os botões de WhatsApp)
- [ ] Endereços: Rua Norvinda Pires, **42** (Aldeota). Uma fonte cita o nº 100. Av. Godofredo Maciel, 2127 (Maraponga)
- [ ] Instagrams `@donkeyheadcervejaria` e `@donkeyheadmaraponga`
- [ ] Rótulos e descrições das cervejas (seção `#cervejas` do `index.html`)
- [ ] Texto "Nossa história", que é uma proposta e deve ser ajustado com a história real
- [ ] Chope delivery: barris a partir de 30 L e até 2 estilos por chopeira
- [ ] Link do cardápio online (`donkey-head-cervejaria.ola.click`)
- [ ] Horários de funcionamento (hoje o site manda conferir no Instagram)

## Melhorias recomendadas

- **Logo oficial**: substitua `site/assets/img/logo.svg` (o atual é provisório).
- **Fotos**: coloque uma foto do bar/torneiras em `site/assets/img/hero.jpg` (1920px, < 400 KB) e adicione em `.hero` no CSS:
  `url("../img/hero.jpg") center / cover no-repeat,` antes de `var(--bg);`.
- Fotos das latas/copos para os cards de cerveja.

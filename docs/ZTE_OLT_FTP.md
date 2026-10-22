# Backup FTP da OLT ZTE C350

Procedimento validado em 21/10/2026 na **OLT-LIBANESA**. A C650 ainda aguarda teste de campo. A referência do fabricante foi a página 46 do PDF de treinamento ZTE guardado fora do repositório na pasta de Trevizam.

## 1. Preparar o Backup Manager

1. Cadastre a OLT como fabricante **ZTE**, plataforma **OLT** e mantenha o equipamento ativo.
2. Em **Equipamentos → Ações → Política de backup**, escolha **Criar conta FTP**. Crie uma conta de finalidade **Backup**, vinculada à OLT, com usuário e senha próprios. Aguarde a conta ficar ativa e provisionada.
3. Volte à política do equipamento e associe **ZTE FTP**. A política usa `ftp_push`, artefato de configuração, agendamento **manual** no Backup Manager e retenção de 30 dias. O horário do envio é controlado pela OLT.
4. Em **Configurações → Origens permitidas no FTP**, libere o IPv4 público de origem visto pelo servidor (após NAT) e confirme **Aplicada no firewall**. Nesta instalação, o FTP público usa `45.239.157.250`, TCP **21** e portas passivas **30000–30009**. O IP de gerenciamento pode ser diferente do IP de origem do FTP.

O usuário da conta da OLT-LIBANESA é `olt-libanesa`. Consulte a senha no painel; ela não fica nesta documentação. Substitua `<USUARIO_FTP>` e `<SENHA_FTP>` pelos dados da conta ao executar os comandos abaixo.

## 2. Configurar e testar o envio manual na OLT

Os servidores de backup **manual** e **automático** são configurações separadas na ZTE. Configure o manual para testar a transferência:

```text
ZXAN#configure terminal
ZXAN(config)#file-server manual cfg server-index 1 ftp ipaddress 45.239.157.250 user <USUARIO_FTP> password <SENHA_FTP>
ZXAN(config)#end
ZXAN#write
ZXAN#show file-server manual-backup
ZXAN#manual-backup cfg
ZXAN#show auto-backup progress manual
```

O `show file-server manual-backup` deve mostrar `FileType: Cfg`, `ServerIndex: 1`, protocolo FTP, IP e usuário corretos. Não compartilhe a saída se ela exibir a senha. O teste inicial falhou com `connect server failed` porque somente o servidor **auto-backup** estava configurado. Após configurar o servidor manual, a C350 autenticou no FTP e enviou arquivos.

O `BackupStatus` da OLT e o número `SuccessFiles` devem ser conferidos junto com os **Recebimentos recentes** da conta FTP e os backups do equipamento no painel. No primeiro teste, a OLT informou `Initiate a FTP transfer failed` e mencionou `patch.cfg`, embora dois arquivos tenham chegado; esse resultado não comprova um envio completo de todos os arquivos esperados.

## 3. Configurar o backup automático diário

Configure também o servidor do envio automático. O exemplo abaixo agenda o primeiro disparo para **22/10/2026 às 00:45** pelo relógio da OLT e repete a cada **24 horas**:

```text
ZXAN#configure terminal
ZXAN(config)#file-server auto-backup cfg server-index 1 ftp ipaddress 45.239.157.250 user <USUARIO_FTP> password <SENHA_FTP>
ZXAN(config)#auto-backup period cfg 00:45:00 oct 22 2026 interval 24
ZXAN(config)#end
ZXAN#write
ZXAN#show file-server auto-backup
ZXAN#show auto-backup period configure
```

O comando `auto-backup period` só foi aceito em `ZXAN(config)#`; em `ZXAN#` retornou erro de sintaxe. O `write` terminou com `[OK]`. A saída de `show auto-backup period configure` indicou `cfg enable`, início em `2026-10-22 00:45:00` e intervalo de `24` horas. Log e imagem ficaram desativados. Isso confirma **configuração salva**, não o primeiro envio automático: depois das 00:45, consulte `show auto-backup progress period` e confira o arquivo no painel.

Para outros equipamentos, troque a data de início e confirme que o relógio e o fuso da OLT correspondem ao horário desejado. O Backup Manager não agenda esse FTP Push.

## 4. Nomes de arquivos e validação

Mesmo com `Path` vazio, a C350 criou as subpastas `ZXAN_136.1.1.100/2026-10-21-20.48.50-cfg/` no FTP. O receptor percorre até dois níveis de pastas da conta de backup, identifica o equipamento pela conta FTP e aceita arquivos ZTE `.dat`, `.sav` e `.cfg`. Os diretórios criados pela OLT permanecem disponíveis para ela; os arquivos processados são movidos para o armazenamento de backups.

No teste de 21/10/2026, `startrun.dat` (429.803 bytes) e `startrun.sav` (429.682 bytes) ficaram **stored** nos recibos #194 e #195. As execuções #629 e #630 ficaram **succeeded**; `ArtifactStorage::verify()` confirmou tamanho e SHA-256 válidos nos artefatos #262 e #263. A análise do conteúdo ZTE ainda é `unknown`: a validação atual confirma integridade física e vínculo, sem interpretar semanticamente o formato do fabricante. A pasta do primeiro envio foi colocada em quarentena antes da correção do receptor; o recibo histórico #193 permanece como registro desse incidente. Os dois arquivos foram recuperados sem remover os originais da quarentena.

Se a OLT mostrar `connect server failed`, confira primeiro se configurou o **file-server manual** para um teste manual, o IP FTP, a porta 21 e a origem liberada. Ping ao IP comprova ICMP, não conexão FTP. Se houver login e arquivos enviados nos logs do FTP mas o status final da OLT indicar falha, compare os nomes e quantidades com os recebimentos do painel. O primeiro disparo automático e o arquivo `patch.cfg` ainda aguardam confirmação em campo.

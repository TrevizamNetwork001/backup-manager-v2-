# Backup Manager V2 - teste unico FTP - MK-BORDA-01 - RouterOS 7
# Preencha a senha FTP da conta deste equipamento antes de executar.
:local bmHost "45.239.157.250"
:local bmPort 21
:local bmUser "backup-manager"
:local bmPassword "SENHA_FTP_AQUI"
:local bmBase "backup-manager-test-1"
:local bmFile ($bmBase . ".rsc")
:if ($bmPassword = "SENHA_FTP_AQUI") do={ :error "Preencha a senha FTP antes do teste" }
:if ([:len [/file find where name=$bmFile]] > 0) do={ :error "Arquivo de teste ja existe no MikroTik" }
:do {
  /export file=$bmBase
  :delay 2s
  :local bmFileIds [/file find where name=$bmFile]
  :if ([:len $bmFileIds] != 1) do={ :error "Arquivo de teste indisponivel" }
  :if ([/file get $bmFileIds size] <= 0) do={ :error "Arquivo de teste vazio" }
  /tool fetch mode=ftp upload=yes address=$bmHost port=$bmPort user=$bmUser password=$bmPassword src-path=$bmFile dst-path=$bmFile keep-result=no duration=2m idle-timeout=15s
  /file remove $bmFileIds
} on-error={
  :local bmOriginalError $message
  :local bmCleanup [/file find where name=$bmFile]
  :if ([:len $bmCleanup] > 0) do={ /file remove $bmCleanup }
  :set bmPassword ""
  :error ("Falha no teste FTP: " . $bmOriginalError)
}
:set bmPassword ""
:log info "Teste FTP Backup Manager enviado"

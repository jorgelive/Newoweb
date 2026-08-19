#!/bin/bash
# Script de auto-aprobación con seguridad granular para comandos locales y SSH

PAYLOAD=$(cat)

python3 - << 'EOF'
import sys, json, re

payload = sys.stdin.read()
if not payload.strip():
    print(json.dumps({"decision": "ask"}))
    sys.exit(0)

try:
    data = json.loads(payload)
    tool_call = data.get("toolCall", {})
    name = tool_call.get("name", "")
    args = tool_call.get("args", {})

    # 1. Herramientas nativas de solo lectura en el workspace
    read_only_tools = [
        "view_file",
        "find_by_name",
        "grep_search",
        "list_dir",
        "read_url_content",
        "search_web"
    ]
    if name in read_only_tools:
        print(json.dumps({"decision": "allow", "reason": f"Tool {name} is read-only safe"}))
        sys.exit(0)

    # 2. Comandos de terminal
    if name == "run_command":
        cmd = args.get("CommandLine", "").strip()

        # Lista negra: patrones destructivos universales
        dangerous_patterns = [
            r'\brm\b',
            r'\brmdir\b',
            r'\bDROP\b',
            r'\bTRUNCATE\b',
            r'\bDELETE\b',
            r'\bALTER\b',
            r'\bgit\s+reset\s+--hard\b',
            r'\bgit\s+clean\b',
            r'\bgit\s+push\s+.*--force\b',
            r'\bkill\b',
            r'\bpkill\b',
            r'\breboot\b',
            r'\bshutdown\b',
            r'\bmkfs\b',
            r'\bdd\b'
        ]

        if any(re.search(p, cmd, re.IGNORECASE) for p in dangerous_patterns):
            print(json.dumps({"decision": "ask", "reason": "Contains dangerous command pattern"}))
            sys.exit(0)

        # Reglas específicas para SSH (Servidor Remoto: MÁXIMA PRECISIÓN)
        # NUNCA autorizar "ssh openperu" a secas. Solo subcomandos explícitos y seguros:
        if cmd.startswith("ssh openperu"):
            safe_ssh_subcommands = [
                r'^ssh openperu "(sudo\s+)?tail\b',
                r'^ssh openperu "(sudo\s+)?cat\b',
                r'^ssh openperu "(sudo\s+)?head\b',
                r'^ssh openperu "(sudo\s+)?grep\b',
                r'^ssh openperu "(sudo\s+)?df\b',
                r'^ssh openperu "(sudo\s+)?free\b',
                r'^ssh openperu "(sudo\s+)?ps\b',
                r'^ssh openperu "(sudo\s+)?top\b',
                r'^ssh openperu "(sudo\s+)?uptime\b',
                r'^ssh openperu "(sudo\s+)?journalctl\b',
                r'^ssh openperu "(sudo\s+)?systemctl status\b',
                r'^ssh openperu "(sudo\s+)?(cd\s+/var/www/openperu\.pe\s+&&\s+)?composer dump-env prod"',
                r'^ssh openperu "(sudo\s+)?(cd\s+/var/www/openperu\.pe\s+&&\s+)?php bin/console cache:clear',
                r'^ssh openperu "(sudo\s+)?(cd\s+/var/www/openperu\.pe\s+&&\s+)?php bin/console messenger:stop-workers"',
                r'^ssh openperu "(sudo\s+)?mysql -u debian-sys-maint -p[^\s]+ -e \'(SELECT|SHOW|DESCRIBE)'
            ]

            is_safe_ssh = any(re.search(pattern, cmd) for pattern in safe_ssh_subcommands)
            if is_safe_ssh:
                print(json.dumps({"decision": "allow", "reason": "Safe SSH specific command"}))
                sys.exit(0)
            else:
                print(json.dumps({"decision": "ask", "reason": "SSH command requires explicit confirmation"}))
                sys.exit(0)

        # Comandos locales seguros en tu máquina de desarrollo
        safe_local_prefixes = [
            "git status",
            "git diff",
            "git log",
            "git show",
            "git branch",
            "git blame",
            "git grep",
            "ls",
            "cat",
            "head",
            "tail",
            "grep",
            "find",
            "echo",
            "mkdir",
            "touch",
            "chmod",
            "php bin/console",
            "php -v",
            "composer --version"
        ]

        for prefix in safe_local_prefixes:
            if cmd.startswith(prefix) or cmd.startswith("sudo " + prefix):
                print(json.dumps({"decision": "allow", "reason": f"Safe local command: {prefix}"}))
                sys.exit(0)

    # Por defecto, pedir permiso
    print(json.dumps({"decision": "ask"}))

except Exception as e:
    print(json.dumps({"decision": "ask"}))
EOF

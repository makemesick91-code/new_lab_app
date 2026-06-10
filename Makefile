.PHONY: status test format routes check log-check tail-check sprint-finish-check context install-hooks

status:
	@bash scripts/status.sh

test:
	@bash scripts/test.sh

format:
	@bash scripts/format.sh

routes:
	@bash scripts/routes.sh

check:
	@bash scripts/check.sh

log-check:
	@bash scripts/log-check.sh

tail-check:
	@bash scripts/tail-last-check.sh

sprint-finish-check:
	@bash scripts/sprint-finish-check.sh

context:
	@bash scripts/context-snapshot.sh

install-hooks:
	@bash scripts/install-git-hooks.sh

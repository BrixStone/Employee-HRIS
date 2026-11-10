# ============================================================
# HRIS — DDD/TDD Test Runner
# ============================================================
# Usage:
#   make test              run all 64 tests
#   make test-money        run Money value object tests
#   make test-cutoff       run CutOff value object tests
#   make test-late         run LateDeductionPolicy tests
#   make test-status       run PayslipStatus enum tests
#   make test-payslip      run Payslip aggregate root tests
#   make test-repo         run InMemoryPayslipRepository tests
#   make test-handler      run GeneratePayslipHandler tests
#   make test-domain       run all domain layer tests
#   make test-app          run all application layer tests
#   make seed              run the demo data seeder
#   make describe-logs     show leave_request_logs columns
# ============================================================

CONTAINER   = hris_app
PHPUNIT     = vendor/bin/phpunit
DOMAIN_DIR  = tests/Unit/Domain/Payroll
APP_DIR     = tests/Unit/Application/Payroll

# ── Run everything ───────────────────────────────────────────
test:
	docker exec -it $(CONTAINER) $(PHPUNIT)

# ── Cycle 1 — Money value object ─────────────────────────────
test-money:
	docker exec -it $(CONTAINER) $(PHPUNIT) $(DOMAIN_DIR)/MoneyTest.php

# ── Cycle 2 — CutOff value object ────────────────────────────
test-cutoff:
	docker exec -it $(CONTAINER) $(PHPUNIT) $(DOMAIN_DIR)/CutOffTest.php

# ── Cycle 3 — LateDeductionPolicy domain service (Binary Search)
test-late:
	docker exec -it $(CONTAINER) $(PHPUNIT) $(DOMAIN_DIR)/LateDeductionPolicyTest.php

# ── Cycle 4 — PayslipStatus backed enum ──────────────────────
test-status:
	docker exec -it $(CONTAINER) $(PHPUNIT) $(DOMAIN_DIR)/PayslipStatusTest.php

# ── Cycle 5 — Payslip aggregate root ─────────────────────────
test-payslip:
	docker exec -it $(CONTAINER) $(PHPUNIT) $(DOMAIN_DIR)/PayslipTest.php

# ── Cycle 6 — InMemoryPayslipRepository ──────────────────────
test-repo:
	docker exec -it $(CONTAINER) $(PHPUNIT) $(DOMAIN_DIR)/InMemoryPayslipRepositoryTest.php

# ── Cycle 7 — GeneratePayslipHandler use case ────────────────
test-handler:
	docker exec -it $(CONTAINER) $(PHPUNIT) $(APP_DIR)/GeneratePayslipHandlerTest.php

# ── Layer shortcuts ───────────────────────────────────────────
test-domain:
	docker exec -it $(CONTAINER) $(PHPUNIT) tests/Unit/Domain

test-app:
	docker exec -it $(CONTAINER) $(PHPUNIT) tests/Unit/Application

# ── Database helpers ──────────────────────────────────────────
seed:
	docker exec -it $(CONTAINER) php database/seed_demo_data.php

describe-logs:
	docker exec -it hris_mysql mysql -u root -p hris -e "DESCRIBE leave_request_logs;"

# ── Autoloader ────────────────────────────────────────────────
autoload:
	composer dump-autoload

seed-demo:
	docker exec -it $(CONTAINER) php database/seed_demo_data.php

.PHONY: test report clean login-test

# Run all Selenium tests and generate the Mocha HTML report
test:
	npm test

# Open the generated Mochawesome report
report:
	powershell -NoProfile -Command "Start-Process './mochawesome-report/mochawesome.html'"

# Run the tests and then open the report
test-report: test report

# Remove the previous HTML report
clean:
	powershell -NoProfile -Command "if (Test-Path './mochawesome-report') { Remove-Item './mochawesome-report' -Recurse -Force }"

# Run the HRIS test suite
login-test:
	npx mocha hris.test.js --reporter mochawesome

const { Builder, Browser, By, until } = require('selenium-webdriver');
const assert = require('node:assert/strict');

describe('HRIS Employee Portal Tests', function () {
    this.timeout(30000);

    let driver;

    before(async function () {
        driver = await new Builder()
            .forBrowser(Browser.CHROME)
            .build();
    });

    after(async function () {
        if (driver) {
            await driver.quit();
        }
    });

    // Test 1: Login page loads
    it('UI Test: should load the login page', async function () {
        await driver.get(
            'http://localhost:8000/authentication/login.php'
        );

        await driver.wait(until.elementLocated(By.id('identifier')), 10000);

        assert.equal(
            await driver.getTitle(),
            'Sign in'
        );
    });

    // Test 2: Login form contains required fields
    it('UI Test: should display username, password, and sign-in button', async function () {
        const username = await driver.findElement(By.id('identifier'));
        const password = await driver.findElement(By.id('password'));
        const button = await driver.findElement(
            By.css('button[type="submit"]')
        );

        assert.equal(await username.isDisplayed(), true);
        assert.equal(await password.isDisplayed(), true);
        assert.equal(await button.isDisplayed(), true);
    });

    // Test 3: Username can be entered
    it('UI Test: should accept a username or email', async function () {
        const username = await driver.findElement(By.id('identifier'));

        await username.clear();
        await username.sendKeys('test@example.com');

        assert.equal(
            await username.getAttribute('value'),
            'test@example.com'
        );
    });

    // Test 4: Password field accepts input
    it('UI Test: should accept password input', async function () {
        const password = await driver.findElement(By.id('password'));

        await password.clear();
        await password.sendKeys('WrongPassword123');

        assert.ok(
            (await password.getAttribute('value')).length > 0
        );
    });

    // Test 5: Invalid login should not reach the employee dashboard
    it('UI Test: should reject invalid login credentials', async function () {
        await driver.get(
            'http://localhost:8000/authentication/login.php'
        );

        await driver.findElement(By.id('identifier'))
            .sendKeys('test@example.com');

        await driver.findElement(By.id('password'))
            .sendKeys('WrongPassword123');

        await driver.findElement(
            By.css('button[type="submit"]')
        ).click();

        await driver.sleep(1000);

        const currentUrl = await driver.getCurrentUrl();

        assert.ok(
            currentUrl.includes('/authentication/login.php'),
            `Expected to remain on login page, but reached: ${currentUrl}`
        );
    });
    
    // Test 6: Successfully log in and open employee dashboard
    it('UI Test: should log in and display the employee dashboard', async function () {
        // Replace these with valid credentials for a test employee
        const testUsername = 'brandon';
        const testPassword = 'brandon123';

        await driver.get(
            'http://localhost:8000/authentication/login.php'
        );

        await driver.findElement(By.id('identifier'))
            .sendKeys(testUsername);

        await driver.findElement(By.id('password'))
            .sendKeys(testPassword);

        await driver.findElement(
            By.css('button[type="submit"]')
        ).click();

        // Wait for the dashboard to load
        await driver.wait(
            until.urlContains('/employee/dashboard.php'),
            10000
        );

        // Verify we reached the dashboard
        assert.ok(
            (await driver.getCurrentUrl())
                .includes('/employee/dashboard.php'),
            'Expected to reach the employee dashboard'
        );

        // Verify dashboard content is displayed
        const dashboardHeading = await driver.wait(
            until.elementLocated(
                By.xpath("//*[contains(normalize-space(.), 'Employee Dashboard')]")
            ),
            10000
        );

        assert.equal(await dashboardHeading.isDisplayed(), true);
    });

});

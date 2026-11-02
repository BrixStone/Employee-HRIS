
const { Builder, Browser, By } = require('selenium-webdriver');

async function main() {
    const driver = await new Builder()
        .forBrowser(Browser.CHROME)
        .build();

    try {
        // Open the HRIS login page
        await driver.get('http://localhost:8000/authentication/login.php');

        // Find the form elements
        const username = await driver.findElement(By.id('identifier'));
        const password = await driver.findElement(By.id('password'));
        const signInButton = await driver.findElement(
            By.css('button[type="submit"]')
        );

        // Enter test credentials
        await username.sendKeys('test@example.com');
        await password.sendKeys('WrongPassword123');

        console.log('Username entered:', await username.getAttribute('value'));
        console.log('Password field is not empty:',
            (await password.getAttribute('value')).length > 0
        );

        // Submit the form
        await signInButton.click();

        // Wait for the page to respond
        await driver.sleep(1000);

        console.log('Current URL:', await driver.getCurrentUrl());
        console.log('Page title:', await driver.getTitle());

    } catch (error) {
        console.log('TEST FAILED:', error.message);
    } finally {
        await driver.quit();
    }
}

main();

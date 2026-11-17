<?php
declare(strict_types=1);

use Foodwell\Repositories\CustomerRepository;
use Foodwell\Validation\CustomerCollection;
use Foodwell\Validation\CustomerRegistration;
use PHPUnit\Framework\TestCase;

final class CustomerValidationTest extends TestCase
{
    private function validInput(): array
    {
        return ['firstName'=>' Alex ', 'lastName'=>' Demo ', 'email'=>' ALEX@EXAMPLE.COM ', 'password'=>'  secret-password  '];
    }

    public function testRegistrationNormalizesFieldsWithoutTrimmingPassword(): void
    {
        $result = CustomerRegistration::validate($this->validInput());
        self::assertSame([], $result['errors']);
        self::assertSame('Alex', $result['data']['firstName']);
        self::assertSame('alex@example.com', $result['data']['email']);
        self::assertSame('  secret-password  ', $result['data']['password']);
        self::assertNull($result['data']['phone']);
    }

    /** @dataProvider invalidPasswords */
    public function testPasswordBoundariesAndNulAreRejected(string $password): void
    {
        $input=$this->validInput(); $input['password']=$password;
        self::assertArrayHasKey('password', CustomerRegistration::validate($input)['errors']);
    }
    public function invalidPasswords(): array { return [['short'], [str_repeat('x', 73)], ["password\0bad"]]; }

    public function testRegistrationRejectsPrivilegeFieldsAndUpdateRejectsPassword(): void
    {
        $input=$this->validInput(); $input['role']='admin'; $input['isActive']=false;
        $errors=CustomerRegistration::validate($input)['errors'];
        self::assertArrayHasKey('role',$errors); self::assertArrayHasKey('isActive',$errors);
        self::assertArrayHasKey('password',CustomerRegistration::validate($this->validInput(),false)['errors']);
    }

    public function testCollectionRejectsInvalidPagingAndMultipleValues(): void
    {
        $errors=CustomerCollection::validate(['page'=>'0','limit'=>'101','isActive'=>['true']])['errors'];
        self::assertArrayHasKey('page',$errors); self::assertArrayHasKey('limit',$errors); self::assertArrayHasKey('isActive',$errors);
    }

    public function testRepositoryHashesPasswordWithoutExposingIt(): void
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE customers(id INTEGER PRIMARY KEY AUTOINCREMENT,first_name TEXT,last_name TEXT,email TEXT,password_hash TEXT,address TEXT,phone TEXT,role TEXT,is_active INTEGER)');
        $valid=CustomerRegistration::validate($this->validInput());
        $result=(new CustomerRepository($db))->register($valid['data']);
        $stored=$db->query('SELECT password_hash,role,is_active FROM customers')->fetch(PDO::FETCH_ASSOC);
        self::assertTrue(password_verify('  secret-password  ', $stored['password_hash']));
        self::assertFalse(password_verify('secret-password', $stored['password_hash']));
        self::assertNotSame($valid['data']['password'],$stored['password_hash']);
        self::assertSame('customer',$stored['role']); self::assertSame(1,(int)$stored['is_active']);
        self::assertArrayNotHasKey('password',$result); self::assertArrayNotHasKey('password_hash',$result);
    }
}

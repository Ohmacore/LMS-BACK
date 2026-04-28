#!/bin/bash

# E-Learning Platform - Quick Start Script

echo "========================================="
echo "E-Learning Platform - Quick Start"
echo "========================================="
echo ""

# Function to check if PostgreSQL is running
check_postgresql() {
    if systemctl is-active --quiet postgresql; then
        echo "✓ PostgreSQL is running"
        return 0
    else
        echo "✗ PostgreSQL is not running"
        echo "  Please start it with: sudo systemctl start postgresql"
        return 1
    fi
}

# Check PostgreSQL
if ! check_postgresql; then
    exit 1
fi

echo ""
echo "Creating database..."
echo "NOTE: You may be prompted for your sudo password"
sudo -u postgres psql -c "DROP DATABASE IF EXISTS elearning;" 2>/dev/null
sudo -u postgres psql -c "CREATE DATABASE elearning;" 2>/dev/null

if [ $? -eq 0 ]; then
    echo "✓ Database created successfully"
else
    echo "✗ Failed to create database"
    echo "  You can create it manually:"
    echo "  sudo -u postgres psql -c 'CREATE DATABASE elearning;'"
    exit 1
fi

echo ""
echo "Running migrations..."
php artisan migrate --force

if [ $? -eq 0 ]; then
    echo "✓ Migrations completed successfully"
else
    echo "✗ Migrations failed"
    exit 1
fi

echo ""
echo "Seeding database..."
php artisan db:seed --force

if [ $? -eq 0 ]; then
    echo "✓ Database seeded successfully"
else
    echo "✗ Seeding failed"
    exit 1
fi

echo ""
echo "========================================="
echo "Setup Complete!"
echo "========================================="
echo ""
echo "Login credentials:"
echo "  Admin:     admin@elearning.com / password"
echo "  Teacher 1: ahmed@elearning.com / password"
echo "  Teacher 2: fatima@elearning.com / password"
echo "  Students:  student1@elearning.com to student5@elearning.com / password"
echo ""
echo "To start the backend server:"
echo "  php artisan serve"
echo ""

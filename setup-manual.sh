#!/bin/bash

echo "========================================="
echo "E-Learning Platform - Manual Setup"
echo "========================================="
echo ""
echo "This script will create the database without needing sudo."
echo ""

# Check if database exists
DB_EXISTS=$(psql -U $USER -lqt 2>/dev/null | cut -d \| -f 1 | grep -qw elearning && echo "yes" || echo "no")

if [ "$DB_EXISTS" = "yes" ]; then
    echo "✓ Database 'elearning' already exists"
else
    echo "Creating database as current user..."
    createdb elearning 2>/dev/null && echo "✓ Database created" || echo "⚠ Using existing database or need permissions"
fi

# Update .env to use current user
echo ""
echo "Updating .env file..."
sed -i "s/DB_USERNAME=postgres/DB_USERNAME=$USER/" .env
sed -i "s/DB_PASSWORD=/DB_PASSWORD=/" .env

echo "✓ Database configuration updated"
echo ""
echo "Running migrations..."
php artisan migrate --force

if [ $? -eq 0 ]; then
    echo "✓ Migrations completed"
    echo ""
    echo "Seeding database..."
    php artisan db:seed --force
    
    if [ $? -eq 0 ]; then
        echo ""
        echo "========================================="
        echo "✅ Setup Complete!"
        echo "========================================="
        echo ""
        echo "Login credentials:"
        echo "  Admin:     admin@elearning.com / password"
        echo "  Teacher 1: ahmed@elearning.com / password"
        echo "  Teacher 2: fatima@elearning.com / password"
        echo "  Students:  student1@elearning.com to student5@elearning.com / password"
        echo ""
        echo "Backend is running on: http://localhost:8001"
        echo "Frontend is running on: http://localhost:3000"
        echo ""
        echo "Go to http://localhost:3000/auth/login to test!"
    else
        echo "✗ Seeding failed"
        exit 1
    fi
else
    echo "✗ Migrations failed"
    exit 1
fi

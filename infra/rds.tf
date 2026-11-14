data "aws_security_group" "rds" {
  filter {
    name   = "tag:Name"
    values = ["rds"]
  }
  filter {
    name   = "vpc-id"
    values = [data.aws_vpc.kubernetes.id]
  }
}

resource "aws_db_instance" "db" {
  identifier              = "${var.project_name}-mysql"
  engine                  = "mysql"
  engine_version          = "8.4.11"
  instance_class          = "db.t3.micro"
  allocated_storage       = 20
  max_allocated_storage   = 100
  storage_type            = "gp2"
  db_name                 = var.db_name
  username                = var.db_username
  password                = var.db_password
  db_subnet_group_name    = aws_db_subnet_group.this.name
  vpc_security_group_ids  = [data.aws_security_group.rds.id]
  publicly_accessible     = true
  skip_final_snapshot     = true
  multi_az                = false
  backup_retention_period = 0

  tags = {
    Name = "${var.project_name}-mysql"
  }
}

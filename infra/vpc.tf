data "aws_vpc" "kubernetes" {
  filter {
    name   = "tag:Name"
    values = ["main"]
  }
}

data "aws_subnets" "kubernetes_public" {
  filter {
    name   = "vpc-id"
    values = [data.aws_vpc.kubernetes.id]
  }

  filter {
    name   = "tag:Name"
    values = ["sub_a", "sub_b"]
  }
}

resource "aws_db_subnet_group" "this" {
  name       = "${var.project_name}-db-subnet-group"
  subnet_ids = data.aws_subnets.kubernetes_public.ids

  tags = {
    Name = "${var.project_name}-db-subnet-group"
  }
}
